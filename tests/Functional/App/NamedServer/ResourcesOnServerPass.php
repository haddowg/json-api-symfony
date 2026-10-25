<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Moves every resource onto the named server {@see self::SERVER}.
 */
final class ResourcesOnServerPass implements CompilerPassInterface
{
    public const string SERVER = 'v5';

    public function process(ContainerBuilder $container): void
    {
        foreach (\array_keys($container->findTaggedServiceIds(JsonApiBundle::RESOURCE_TAG)) as $id) {
            $definition = $container->getDefinition($id);
            $tags = $definition->getTags();
            $tags[JsonApiBundle::RESOURCE_TAG] = \array_map(
                static fn(array $tag): array => ['server' => self::SERVER] + $tag,
                $tags[JsonApiBundle::RESOURCE_TAG] ?? [],
            );
            $definition->setTags($tags);
        }
    }
}
