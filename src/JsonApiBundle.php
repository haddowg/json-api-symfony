<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\Attribute\AsJsonApiHydrator;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
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
     * registry reads it to resolve a provider per resource type: providers are
     * consulted in descending tag `priority` order (default `0`), first
     * `supports()` match wins. The bundled Doctrine provider registers at
     * `-128`, so an application provider shadows it for the types it supports
     * without any priority configuration.
     */
    public const string DATA_PROVIDER_TAG = 'haddowg.json_api.data_provider';

    /**
     * Tag applied to every {@see DataPersisterInterface}. The data-persister
     * registry reads it to resolve a persister per resource type, with the same
     * descending-`priority`, first-`supports()`-match semantics as
     * {@see self::DATA_PROVIDER_TAG}; the bundled Doctrine persister registers at
     * `-128` as the fallback.
     */
    public const string DATA_PERSISTER_TAG = 'haddowg.json_api.data_persister';

    /**
     * Tag applied to every {@see \haddowg\JsonApiBundle\Validation\ConstraintTranslatorInterface}.
     * The {@see \haddowg\JsonApiBundle\Validation\ConstraintTranslator} consults them
     * (descending tag `priority`, first `supports()` match) to translate a custom
     * constraint value object — one outside core's built-in vocabulary — into
     * Symfony rules.
     */
    public const string CONSTRAINT_TRANSLATOR_TAG = 'haddowg.json_api.constraint_translator';

    /**
     * Tag applied to every {@see DoctrineExtensionInterface}. The Doctrine
     * provider applies every supporting extension to each query it builds, in
     * descending tag `priority` order (default `0`), before the requested
     * criteria.
     */
    public const string DOCTRINE_EXTENSION_TAG = 'haddowg.json_api.doctrine_extension';

    /**
     * Tag applied to a standalone {@see \haddowg\JsonApi\Serializer\SerializerInterface}
     * registered for a type via {@see AsJsonApiSerializer} — a serializer without an
     * {@see AbstractResource} (bundle ADR 0024). The tag carries the `type` it
     * serializes; {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
     * reads it to register the type's serializer with core.
     */
    public const string SERIALIZER_TAG = 'haddowg.json_api.serializer';

    /**
     * Tag applied to a standalone {@see \haddowg\JsonApi\Hydrator\HydratorInterface}
     * registered for a type via {@see AsJsonApiHydrator} — the decoupled write half
     * (bundle ADR 0024). The tag carries the `type` it hydrates.
     */
    public const string HYDRATOR_TAG = 'haddowg.json_api.hydrator';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('base_uri')->defaultValue('')->end()
                ->scalarNode('version')->defaultValue('1.1')->end()
                ->booleanNode('schema_validation')
                    ->info('Validate write bodies against the JSON:API JSON Schema (requires opis/json-schema). A dev/CI structural linter, separate from the always-on Symfony Validator bridge.')
                    ->defaultFalse()
                ->end()
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

        // The optional opis structural linter: registered (so the RequestListener
        // picks it up) only when explicitly enabled. opis/json-schema is a
        // `suggest` dependency, so a misconfiguration (enabled without it) fails
        // the build with a clear message rather than at the first write.
        if (($config['schema_validation'] ?? false) === true) {
            if (!\class_exists(\Opis\JsonSchema\Validator::class)) {
                throw new \LogicException(
                    'json_api.schema_validation is enabled but opis/json-schema is not installed; '
                    . 'require opis/json-schema (dev/CI) to use the structural document linter.',
                );
            }

            $services = $container->services();
            $services->set(\haddowg\JsonApi\Validation\VendoredSchemaProvider::class);
            $services->set(\haddowg\JsonApi\Validation\DocumentValidator::class)
                ->args([
                    \Symfony\Component\DependencyInjection\Loader\Configurator\service(\haddowg\JsonApi\Validation\VendoredSchemaProvider::class),
                ]);
        }

        // Any service extending AbstractResource is auto-tagged for registration;
        // ResourceLocatorPass keys them by class-string for the Server factory.
        $builder->registerForAutoconfiguration(AbstractResource::class)
            ->addTag(self::RESOURCE_TAG);

        $builder->registerForAutoconfiguration(DataProviderInterface::class)
            ->addTag(self::DATA_PROVIDER_TAG);

        $builder->registerForAutoconfiguration(DataPersisterInterface::class)
            ->addTag(self::DATA_PERSISTER_TAG);

        $builder->registerForAutoconfiguration(DoctrineExtensionInterface::class)
            ->addTag(self::DOCTRINE_EXTENSION_TAG);

        // The constraint-translator interface references Symfony Validator types,
        // so only register its autoconfiguration when the optional
        // symfony/validator dependency is installed.
        if (\interface_exists(\Symfony\Component\Validator\Validator\ValidatorInterface::class)) {
            $builder->registerForAutoconfiguration(\haddowg\JsonApiBundle\Validation\ConstraintTranslatorInterface::class)
                ->addTag(self::CONSTRAINT_TRANSLATOR_TAG);
        }

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
                    'serializer' => $attribute->serializer,
                    'hydrator' => $attribute->hydrator,
                ], static fn(mixed $value): bool => $value !== null));
            },
        );

        // Standalone serializer/hydrator capabilities: a class implementing
        // SerializerInterface/HydratorInterface registers for a type with no
        // AbstractResource (ADR 0024). A single class may carry both.
        $builder->registerAttributeForAutoconfiguration(
            AsJsonApiSerializer::class,
            static function (Definition $definition, AsJsonApiSerializer $attribute): void {
                $definition->addTag(self::SERIALIZER_TAG, ['type' => $attribute->type]);
            },
        );

        $builder->registerAttributeForAutoconfiguration(
            AsJsonApiHydrator::class,
            static function (Definition $definition, AsJsonApiHydrator $attribute): void {
                $definition->addTag(self::HYDRATOR_TAG, ['type' => $attribute->type]);
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
