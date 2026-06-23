<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
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
     *                         or an `AbstractResource` does not declare exactly one Id
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
            }
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
            $serializer->fields(),
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
}
