<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Server\ResourceLocator;
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

        foreach ($container->findTaggedServiceIds(JsonApiBundle::RESOURCE_TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);
            if ($class === null) {
                continue;
            }

            $references[$class] = new Reference($id);
            $classes[] = $class;
        }

        $locator = ServiceLocatorTagPass::register($container, $references);

        $definition = $container->getDefinition(ResourceLocator::class);
        $definition->setArgument('$services', $locator);
        $definition->setArgument('$classes', \array_values(\array_unique($classes)));
    }
}
