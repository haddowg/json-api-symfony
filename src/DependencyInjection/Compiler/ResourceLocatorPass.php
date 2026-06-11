<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\JsonApiBundle;
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
        }

        $standaloneSerializers = $this->collectStandalone(
            $container,
            JsonApiBundle::SERIALIZER_TAG,
            SerializerInterface::class,
            $references,
        );
        $standaloneHydrators = $this->collectStandalone(
            $container,
            JsonApiBundle::HYDRATOR_TAG,
            HydratorInterface::class,
            $references,
        );

        $locator = ServiceLocatorTagPass::register($container, $references);

        $definition = $container->getDefinition(ResourceLocator::class);
        $definition->setArgument('$services', $locator);
        $definition->setArgument('$classes', \array_values(\array_unique($classes)));

        if ($container->hasDefinition(ServerFactory::class)) {
            $factory = $container->getDefinition(ServerFactory::class);
            $factory->setArgument('$serializersByClass', $serializers);
            $factory->setArgument('$hydratorsByClass', $hydrators);
            $factory->setArgument('$standaloneSerializers', $standaloneSerializers);
            $factory->setArgument('$standaloneHydrators', $standaloneHydrators);
        }
    }

    /**
     * Collects the standalone capability services tagged `$tag` into a
     * `type → class-string` map, adding each to `$references` so core's resolver
     * can construct it. Validates the class implements `$contract`.
     *
     * @param class-string             $contract
     * @param array<string, Reference> $references
     *
     * @return array<string, string>
     */
    private function collectStandalone(ContainerBuilder $container, string $tag, string $contract, array &$references): array
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
