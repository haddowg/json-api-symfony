<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass;
use haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Integrates {@see \haddowg\JsonApi\Server\Server} with Symfony: it discovers
 * JSON:API Resource services, builds the per-version Server(s) from configuration,
 * and drives the request lifecycle through kernel listeners.
 *
 * The configuration tree is intentionally minimal: the single-API common case
 * needs no `servers` block (everything lands on an implicit `default` server).
 * See CLAUDE.md and the bundle-plan memory for the full design.
 */
final class JsonApiBundle extends AbstractBundle
{
    /**
     * Tag applied to every discovered Resource service. The Server factory reads
     * it (through the {@see \haddowg\JsonApiBundle\Server\ResourceLocator}) to
     * populate the resource registry.
     */
    public const string RESOURCE_TAG = 'haddowg.json_api.resource';

    /**
     * Tag applied to every {@see DataProviderInterface}. The data-provider
     * registry reads it to resolve a provider per resource type.
     */
    public const string DATA_PROVIDER_TAG = 'haddowg.json_api.data_provider';

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
        $builder->setParameter('haddowg_json_api.base_uri', $this->stringConfig($config, 'base_uri', ''));
        $builder->setParameter('haddowg_json_api.version', $this->stringConfig($config, 'version', '1.1'));

        $container->import(\dirname(__DIR__) . '/config/services.php');

        // Any service extending AbstractResource is auto-tagged for registration;
        // ResourceLocatorPass keys them by class-string for the Server factory.
        $builder->registerForAutoconfiguration(AbstractResource::class)
            ->addTag(self::RESOURCE_TAG);

        $builder->registerForAutoconfiguration(DataProviderInterface::class)
            ->addTag(self::DATA_PROVIDER_TAG);

        // #[AsJsonApiResource] also tags a class as a Resource (so an attribute on
        // a class that is not an AbstractResource subclass is still discovered),
        // and its overrides (`type`, `server`, the Doctrine `entity` mapping) are
        // recorded as tag attributes for the compiler passes to read.
        $builder->registerAttributeForAutoconfiguration(
            AsJsonApiResource::class,
            static function (Definition $definition, AsJsonApiResource $attribute): void {
                $definition->addTag(self::RESOURCE_TAG, \array_filter([
                    'type' => $attribute->type,
                    'server' => $attribute->server,
                    'entity' => $attribute->entity,
                ], static fn(mixed $value): bool => $value !== null));
            },
        );
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ResourceLocatorPass());
        $container->addCompilerPass(new DoctrineEntityMapPass());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function stringConfig(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;

        return \is_string($value) ? $value : $default;
    }
}
