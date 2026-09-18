<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\OpenApi\ProjectedTypes;
use haddowg\JsonApi\OpenApi\RelatedTypeNotRegistered;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiBundle\Operation\Operation;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Asserts at `cache:warmup` that every registered type is actually **servable** —
 * that the capabilities its exposed routes require are present — so a
 * configuration mistake fails the BUILD (`cache:clear` / deploy) instead of a
 * runtime 500 (or, worse, a silently malformed response) on the first request.
 *
 * The bundle already fails the build on a bad `on()` eager chain
 * ({@see \haddowg\JsonApiBundle\Serializer\EagerLoadWarmer}); this is the symmetric
 * guard for the rest of the surface. Like that warmer it is **not optional**
 * ({@see isOptional()} returns `false`), so its `\LogicException` propagates out of
 * `cache:warmup` and aborts the deploy. It checks, per `(server, type)`:
 *
 *  - **A read operation needs a {@see \haddowg\JsonApiBundle\DataProvider\DataProviderInterface}.**
 *    Collection/single fetch (and update/delete, which load-then-mutate) cannot run
 *    without a provider that supports the type; the most common cause is a forgotten
 *    `entity:` on `#[AsJsonApiResource]` (the Doctrine provider supports only mapped
 *    types).
 *  - **A write operation needs a {@see \haddowg\JsonApiBundle\DataPersister\DataPersisterInterface}.**
 *    Declaring a hydrator is necessary but not sufficient — the persister commits it.
 *  - **An `AbstractResource` must declare exactly one {@see Id} field.** A missing Id
 *    otherwise serializes every object of the type with `id: ""` on every response
 *    (and in every `?include`), with no boot-time signal.
 *  - **A polymorphic relation's candidate serializers must discriminate by class.**
 *    A polymorphic relation ({@see RelationInterface::relatedTypes()} of two or more
 *    types) resolves its per-member serializer in core's
 *    {@see RelationInterface::resolveSerializer()} by returning the first declared
 *    related type whose `serializer->getType($member) === $type`. The base
 *    {@see AbstractResource::getType()} returns `static::$type` UNCONDITIONALLY, so a
 *    candidate that does NOT override `getType()` becomes a silent catch-all — it
 *    claims members that are not its own and mis-serializes them with no signal. The
 *    guard requires every `AbstractResource` candidate of a polymorphic relation to
 *    override `getType()` (to discriminate by class, e.g. with `instanceof`); a custom
 *    (non-`AbstractResource`) serializer owns its own `getType` and is left to it.
 *  - **A relation's related endpoint must point at a type the server registers.**
 *    `GET /{type}/{id}/{rel}` returns the related type as primary data, so a server
 *    that does not register it has neither a serializer to render the response nor a
 *    field inventory to describe it. Core's {@see ProjectedTypes::relatedOnly()}
 *    reports that set and the OpenAPI projector refuses to build a document while it
 *    is non-empty; this raises the same {@see RelatedTypeNotRegistered} at warmup,
 *    where the message names the configured server rather than its document title.
 *
 * Gating is on the per-type operation allow-list, so an embedded-only standalone
 * serializer (no operations) and a relationship-only target (served through its
 * parent's provider) are not false-flagged.
 */
final class ServableResourceWarmer implements CacheWarmerInterface
{
    /**
     * @param list<string> $serverNames the declared server names (including the implicit `default`)
     */
    public function __construct(
        private readonly ServerProvider $servers,
        private readonly RouteDescriptorRegistry $descriptors,
        private readonly DataProviderRegistry $providers,
        private readonly DataPersisterRegistry $persisters,
        private readonly TypeMetadataResolver $typeMetadata,
        private readonly MetadataSource $metadata,
        private readonly array $serverNames,
    ) {}

    /**
     * Not optional: an unservable configuration MUST fail the build, so the
     * `\LogicException` propagates out of `cache:warmup` rather than surfacing as a
     * runtime 500.
     */
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     *
     * @throws \LogicException when a routed type has no provider/persister supporting it,
     *                         an `AbstractResource` does not declare exactly one Id, a
     *                         polymorphic relation has a non-discriminating candidate
     *                         serializer, or a related endpoint points at a type the
     *                         server does not register
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        foreach ($this->serverNames as $serverName) {
            foreach ($this->descriptors->forServer($serverName) as $type => $descriptor) {
                if ($type === '') {
                    continue;
                }

                $this->guardServability($type, $descriptor['operations']);
                $this->guardExactlyOneId($serverName, $type);
                $this->guardPolymorphicDiscrimination($serverName, $type);
            }

            $this->guardRelatedEndpointTargets($serverName);
        }

        // No preloadable class files: a pure build-time guard.
        return [];
    }

    /**
     * @param list<string> $operations the type's exposed CRUD operation allow-list
     */
    private function guardServability(string $type, array $operations): void
    {
        $needsProvider = \array_intersect(
            [Operation::FetchCollection->value, Operation::FetchOne->value, Operation::Update->value, Operation::Delete->value],
            $operations,
        ) !== [];
        if ($needsProvider && !$this->providers->supportsType($type)) {
            throw new \LogicException(\sprintf(
                'The JSON:API type "%s" exposes a read operation but no DataProvider supports it. '
                . 'Map an entity with #[AsJsonApiResource(entity: ...)], register a DataProviderInterface '
                . 'service for it, or remove its read operations from the allow-list.',
                $type,
            ));
        }

        $needsPersister = \array_intersect(
            [Operation::Create->value, Operation::Update->value, Operation::Delete->value],
            $operations,
        ) !== [];
        if ($needsPersister && !$this->persisters->supportsType($type)) {
            throw new \LogicException(\sprintf(
                'The JSON:API type "%s" exposes a write operation but no DataPersister supports it. '
                . 'Map an entity with #[AsJsonApiResource(entity: ...)], register a DataPersisterInterface '
                . 'service for it, or remove its write operations from the allow-list.',
                $type,
            ));
        }
    }

    private function guardExactlyOneId(string $serverName, string $type): void
    {
        $server = $this->servers->get($serverName);
        if (!$server->hasSerializerFor($type)) {
            return;
        }

        $serializer = $server->serializerFor($type);
        if (!$serializer instanceof AbstractResource) {
            return; // a custom serializer owns its own id; only the field DSL is checked here
        }

        $idFields = \array_filter(
            $serializer->allFields(),
            static fn(\haddowg\JsonApi\Resource\Field\FieldInterface $field): bool => $field instanceof Id,
        );

        if (\count($idFields) !== 1) {
            throw new \LogicException(\sprintf(
                'The JSON:API resource for type "%s" must declare exactly one Id field, found %d. '
                . 'Add Id::make() to its fields().',
                $type,
                \count($idFields),
            ));
        }
    }

    /**
     * Asserts that no relation on `$serverName` exposes its related endpoint to a type
     * the server does not register — the fault core's OpenAPI projector refuses to
     * build a document over.
     *
     * {@see ProjectedTypes::relatedOnly()} is the rule and the only thing consulted to
     * decide whether there is a fault. The walk below runs only once that has reported
     * something, purely to attribute the offending type back to the relation that
     * exposed it, so the thrown {@see RelatedTypeNotRegistered} can name the parent and
     * the member — and carry core's own wording for the three ways out.
     */
    private function guardRelatedEndpointTargets(string $serverName): void
    {
        $metadata = $this->metadata->forServer($serverName);
        $offending = ProjectedTypes::relatedOnly($metadata);
        if ($offending === []) {
            return;
        }

        foreach ($metadata->types() as $type) {
            foreach ($type->relations() as $relation) {
                if (!$relation->exposesRelatedEndpoint()) {
                    continue;
                }

                foreach ($relation->relatedTypes() as $relatedType) {
                    if (\in_array($relatedType, $offending, true)) {
                        throw new RelatedTypeNotRegistered($serverName, $type->type(), $relation->name(), $relatedType);
                    }
                }
            }
        }
    }

    /**
     * Asserts that every candidate serializer of a polymorphic relation declared on
     * `$type` discriminates members by class — i.e. an `AbstractResource` candidate
     * overrides {@see AbstractResource::getType()}, the per-member discriminator
     * core's {@see RelationInterface::resolveSerializer()} compares against. A
     * candidate that does not override it returns `static::$type` unconditionally and
     * so silently claims (and mis-serializes) members of its siblings' types.
     *
     * Only polymorphic relations are checked: a monomorphic relation
     * (`relatedTypes()` of one) short-circuits in `resolveSerializer()` and never
     * compares `getType()`, so a non-overriding `getType()` is harmless there. A
     * non-`AbstractResource` (custom) candidate owns its own `getType` and is skipped.
     */
    private function guardPolymorphicDiscrimination(string $serverName, string $type): void
    {
        $server = $this->servers->get($serverName);

        foreach ($this->typeMetadata->relationsFor($server, $type) as $relation) {
            $relatedTypes = $relation->relatedTypes();
            if (\count($relatedTypes) < 2) {
                continue; // a monomorphic relation never compares getType()
            }

            foreach ($relatedTypes as $candidateType) {
                if (!$server->hasSerializerFor($candidateType)) {
                    continue;
                }

                $candidate = $server->serializerFor($candidateType);
                if (!$candidate instanceof AbstractResource) {
                    continue; // a custom serializer owns its own getType
                }

                $declaringClass = (new \ReflectionMethod($candidate, 'getType'))->getDeclaringClass()->getName();
                if ($declaringClass === AbstractResource::class) {
                    throw new \LogicException(\sprintf(
                        'The polymorphic relationship "%s" on type "%s" lists candidate type "%s", whose '
                        . 'resource (%s) does not override getType(): it returns its static $type for every '
                        . 'object, so it would silently claim and mis-serialize members of the relationship\'s '
                        . 'other types. Override getType() on %s to discriminate the member by class (e.g. with '
                        . 'instanceof).',
                        $relation->name(),
                        $type,
                        $candidateType,
                        $candidate::class,
                        $candidate::class,
                    ));
                }
            }
        }
    }
}
