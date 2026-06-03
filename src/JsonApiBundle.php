<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle;

use haddowg\JsonApi\Resource\AbstractResource;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Integrates {@see \haddowg\JsonApi\Server\Server} with Symfony: it discovers
 * JSON:API Resource services, builds the per-version Server(s) from configuration,
 * and drives the request lifecycle through kernel listeners.
 *
 * The configuration tree is intentionally minimal at this scaffolding stage; the
 * single-API common case needs no `servers` block (everything lands on an implicit
 * `default` server). See CLAUDE.md and the bundle-plan memory for the full design.
 */
final class JsonApiBundle extends AbstractBundle
{
    /**
     * Tag applied to every discovered Resource service. The Server factory reads
     * it to populate the resource registry.
     */
    public const string RESOURCE_TAG = 'haddowg.json_api.resource';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('base_uri')->defaultValue('')->end()
                ->scalarNode('version')->defaultValue('1.1')->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(\dirname(__DIR__) . '/config/services.php');

        // Any service extending AbstractResource is auto-tagged for registration.
        // Attribute-driven discovery (#[AsJsonApiResource]) and the Server factory
        // that consumes this tag are wired in Phase 0.
        $builder->registerForAutoconfiguration(AbstractResource::class)
            ->addTag(self::RESOURCE_TAG);
    }
}
