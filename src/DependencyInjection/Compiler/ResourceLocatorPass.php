<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Server\RelationsProviderInterface;
use haddowg\JsonApiBundle\Server\RelationsRegistry;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects every service tagged {@see JsonApiBundle::RESOURCE_TAG} and wires the
 * {@see ResourceLocator} from them: a PSR-11 service locator keyed by each
 * Resource's class-string, plus the ordered list of those class-strings.
 *
 * Keying by class-string is what lets core's `ResourceRegistry` read each
 * Resource's `static $type` without instantiating and then resolve the instance
 * (a real Symfony service, dependencies and all) lazily on first lookup.
 *
 * A resource may also declare a custom serializer/hydrator via
 * `#[AsJsonApiResource(serializer: …, hydrator: …)]` (bundle ADR 0023). Those
 * override classes must be registered services too, so each is added to the same
 * locator (keyed by its class-string) — core resolves them through the very same
 * resolver — and a `resourceClass → override` map is handed to the
 * {@see ServerFactory}, which passes each to core's
 * {@see \haddowg\JsonApi\Server\Server::register()} so the type's reads/writes run
 * through the override.
 *
 * Standalone serializer/hydrator capabilities (`#[AsJsonApiSerializer]` /
 * `#[AsJsonApiHydrator]`, bundle ADR 0024) — a serializer/hydrator registered for
 * a type with **no** resource — flow the same way: each service joins the locator
 * (keyed by its class-string) and a `type → class` map is handed to the
 * {@see ServerFactory}, which registers them with core's
 * {@see \haddowg\JsonApi\Server\Server::registerSerializerHydrator()}.
 *
 * Standalone relations (`#[AsJsonApiRelations]` on a
 * {@see RelationsProviderInterface} class, bundle ADR 0026) declare a type's
 * relations with **no** resource: the pass collects them into a type-keyed service
 * locator and wires the {@see RelationsRegistry} from it (lazy, because relations
 * are runtime objects, not scalars). A resource-less type that declares relations is
 * recorded so its descriptor gains relationship routes.
 *
 * The pass also builds a per-type **route descriptor** map (the exposed
 * operation allow-list, the URI segment, whether the type is a full resource, has a
 * hydrator and has relations) and hands it to the {@see JsonApiRouteLoader}, which
 * emits exactly one route per declared operation (bundle ADR 0025) plus the
 * relationship routes for a type that has relations. Descriptors flow as plain
 * scalar arrays — strings, bools and lists of strings — because Symfony cannot dump
 * arbitrary value objects as a compiled service argument. A write operation
 * (`Create`/`Update`) is compile-time-validated to have a hydrator.
 */
final class ResourceLocatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ResourceLocator::class)) {
            return;
        }

        $references = [];
        $classes = [];
        /** @var array<string, string> $serializers */
        $serializers = [];
        /** @var array<string, string> $hydrators */
        $hydrators = [];
        /** @var array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>}> $descriptors */
        $descriptors = [];

        // Standalone relations providers, keyed by type: the registry resolves the
        // provider lazily, and a resource-less type that declares relations gets
        // relationship routes.
        $relationReferences = [];
        $typesWithStandaloneRelations = [];
        foreach ($container->findTaggedServiceIds(JsonApiBundle::RELATIONS_TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);
            if ($class === null) {
                continue;
            }

            if (!\is_a($class, RelationsProviderInterface::class, true)) {
                throw new \LogicException(\sprintf(
                    'The service "%s" registered with #[AsJsonApiRelations] must implement %s.',
                    $id,
                    RelationsProviderInterface::class,
                ));
            }

            foreach ($tags as $tag) {
                $type = $tag['type'] ?? null;
                if (!\is_string($type) || $type === '') {
                    continue;
                }

                $relationReferences[$type] = new Reference($id);
                $typesWithStandaloneRelations[$type] = true;
            }
        }

        foreach ($container->findTaggedServiceIds(JsonApiBundle::RESOURCE_TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);
            if ($class === null) {
                continue;
            }

            $references[$class] = new Reference($id);
            $classes[] = $class;

            foreach ($tags as $tag) {
                $serializer = $this->overrideClass($tag, 'serializer', SerializerInterface::class, $id, $container);
                if ($serializer !== null) {
                    $references[$serializer] = new Reference($serializer);
                    $serializers[$class] = $serializer;
                }

                $hydrator = $this->overrideClass($tag, 'hydrator', HydratorInterface::class, $id, $container);
                if ($hydrator !== null) {
                    $references[$hydrator] = new Reference($hydrator);
                    $hydrators[$class] = $hydrator;
                }
            }

            // A resource carries two RESOURCE_TAG tags when it also bears the
            // attribute (one from AbstractResource autoconfiguration, one from the
            // #[AsJsonApiResource] callback), so the type and the operations
            // allow-list are resolved across all of them, not per-tag — the empty
            // autoconfig tag must not erase the attribute's operations.
            $type = $this->resourceTypeFromTags($tags, $class);
            if ($type === '') {
                continue;
            }

            // The type/uriType are read statically from an AbstractResource (no
            // instantiation); a bare #[AsJsonApiResource] on a non-resource class
            // carries no statics, so its uriType falls back to the type. A resource
            // exposes all five operations by default; an explicit operations string
            // on any tag is the allow-list.
            $uriType = \is_a($class, AbstractResource::class, true) && $class::$uriType !== ''
                ? $class::$uriType
                : $type;

            $descriptors[$type] = [
                'uriType' => $uriType,
                'isResource' => true,
                'hasHydrator' => true,
                'hasRelations' => true,
                'operations' => $this->operationsFromTags($tags) ?? self::allOperations(),
            ];
        }

        /** @var array<string, list<string>> $standaloneSerializerOperations */
        $standaloneSerializerOperations = [];
        $standaloneSerializers = $this->collectStandalone(
            $container,
            JsonApiBundle::SERIALIZER_TAG,
            SerializerInterface::class,
            $references,
            $standaloneSerializerOperations,
        );
        $standaloneHydrators = $this->collectStandalone(
            $container,
            JsonApiBundle::HYDRATOR_TAG,
            HydratorInterface::class,
            $references,
        );

        // A standalone (resource-less) type is serialize-only by default: no
        // endpoints unless its serializer's operations allow-list opens them.
        foreach ($standaloneSerializers as $type => $class) {
            if (isset($descriptors[$type])) {
                continue;
            }

            $descriptors[$type] = [
                'uriType' => $type,
                'isResource' => false,
                'hasHydrator' => isset($standaloneHydrators[$type]),
                'hasRelations' => isset($typesWithStandaloneRelations[$type]),
                'operations' => $standaloneSerializerOperations[$type] ?? [],
            ];
        }

        $this->validateWriteCapability($descriptors);

        $locator = ServiceLocatorTagPass::register($container, $references);

        $definition = $container->getDefinition(ResourceLocator::class);
        $definition->setArgument('$services', $locator);
        $definition->setArgument('$classes', \array_values(\array_unique($classes)));

        // The relations registry is type-keyed (relations are runtime objects, not
        // statically readable scalars), so it gets its own locator.
        if ($container->hasDefinition(RelationsRegistry::class)) {
            $relationsLocator = ServiceLocatorTagPass::register($container, $relationReferences);
            $container->getDefinition(RelationsRegistry::class)->setArgument('$providers', $relationsLocator);
        }

        if ($container->hasDefinition(ServerFactory::class)) {
            $factory = $container->getDefinition(ServerFactory::class);
            $factory->setArgument('$serializersByClass', $serializers);
            $factory->setArgument('$hydratorsByClass', $hydrators);
            $factory->setArgument('$standaloneSerializers', $standaloneSerializers);
            $factory->setArgument('$standaloneHydrators', $standaloneHydrators);
        }

        if ($container->hasDefinition(JsonApiRouteLoader::class)) {
            $container->getDefinition(JsonApiRouteLoader::class)->setArgument('$routeDescriptors', $descriptors);
        }
    }

    /**
     * The JSON:API type for a resource across its tags: the first tag's `type`
     * override, else the `AbstractResource`'s static `$type` (a non-resource class
     * carrying only the attribute has no static, so its type must come from a tag —
     * else empty).
     *
     * @param array<array<string, mixed>> $tags
     */
    private function resourceTypeFromTags(array $tags, string $class): string
    {
        foreach ($tags as $tag) {
            $type = $tag['type'] ?? null;
            if (\is_string($type) && $type !== '') {
                return $type;
            }
        }

        return \is_a($class, AbstractResource::class, true) ? $class::$type : '';
    }

    /**
     * The exposed operation allow-list declared across a resource's tags, or `null`
     * when no tag carries an explicit `operations` string (meaning: use the default).
     *
     * @param array<array<string, mixed>> $tags
     *
     * @return list<string>|null
     */
    private function operationsFromTags(array $tags): ?array
    {
        foreach ($tags as $tag) {
            if (\array_key_exists('operations', $tag)) {
                return $this->parseOperations($tag['operations']);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function allOperations(): array
    {
        return \array_map(static fn(Operation $op): string => $op->value, Operation::cases());
    }

    /**
     * Parses the comma-joined operations tag string into a list of valid
     * {@see Operation} case values; anything unrecognised is dropped.
     *
     * @return list<string>
     */
    private function parseOperations(mixed $operations): array
    {
        if (!\is_string($operations) || $operations === '') {
            return [];
        }

        $values = [];
        foreach (\explode(',', $operations) as $value) {
            $operation = Operation::tryFrom($value);
            if ($operation !== null) {
                $values[] = $operation->value;
            }
        }

        return $values;
    }

    /**
     * Compile-time guard: a type cannot expose a write operation (`Create` /
     * `Update`) without a hydrator to populate the entity.
     *
     * @param array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>}> $descriptors
     */
    private function validateWriteCapability(array $descriptors): void
    {
        foreach ($descriptors as $type => $descriptor) {
            if ($descriptor['hasHydrator']) {
                continue;
            }

            $writes = \array_intersect([Operation::Create->value, Operation::Update->value], $descriptor['operations']);
            if ($writes === []) {
                continue;
            }

            throw new \LogicException(\sprintf(
                'The JSON:API type "%s" exposes a write operation (%s) but has no hydrator; '
                . 'register #[AsJsonApiHydrator(type: "%s")] or use an AbstractResource.',
                $type,
                \implode(', ', $writes),
                $type,
            ));
        }
    }

    /**
     * Collects the standalone capability services tagged `$tag` into a
     * `type → class-string` map, adding each to `$references` so core's resolver
     * can construct it. Validates the class implements `$contract`. When
     * `$operationsByType` is provided, each type's parsed operation allow-list
     * (from the tag's operations string, else empty) is recorded into it.
     *
     * @param class-string                    $contract
     * @param array<string, Reference>        $references
     * @param array<string, list<string>>|null $operationsByType
     *
     * @return array<string, string>
     */
    private function collectStandalone(ContainerBuilder $container, string $tag, string $contract, array &$references, ?array &$operationsByType = null): array
    {
        $map = [];

        foreach ($container->findTaggedServiceIds($tag) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);
            if ($class === null) {
                continue;
            }

            if (!\is_a($class, $contract, true)) {
                throw new \LogicException(\sprintf(
                    'The service "%s" registered for a JSON:API type must implement %s.',
                    $id,
                    $contract,
                ));
            }

            foreach ($tags as $attributes) {
                $type = $attributes['type'] ?? null;
                if (!\is_string($type) || $type === '') {
                    continue;
                }

                $references[$class] = new Reference($id);
                $map[$type] = $class;

                if ($operationsByType !== null) {
                    $operationsByType[$type] = \array_key_exists('operations', $attributes)
                        ? $this->parseOperations($attributes['operations'])
                        : [];
                }
            }
        }

        return $map;
    }

    /**
     * The override class declared under `$key` on a resource tag, validated to be
     * a registered service implementing `$contract`, or `null` when not declared.
     *
     * @param array<string, mixed> $tag
     * @param class-string         $contract
     */
    private function overrideClass(array $tag, string $key, string $contract, string $id, ContainerBuilder $container): ?string
    {
        $class = $tag[$key] ?? null;
        if (!\is_string($class) || $class === '') {
            return null;
        }

        if (!$container->hasDefinition($class) && !$container->hasAlias($class)) {
            throw new \LogicException(\sprintf(
                'The %s "%s" declared by #[AsJsonApiResource] on service "%s" is not a registered service; '
                . 'register it so it can be resolved (with its dependencies).',
                $key,
                $class,
                $id,
            ));
        }

        if (!\is_a($class, $contract, true)) {
            throw new \LogicException(\sprintf(
                'The %s "%s" declared by #[AsJsonApiResource] on service "%s" must implement %s.',
                $key,
                $class,
                $id,
                $contract,
            ));
        }

        return $class;
    }
}
