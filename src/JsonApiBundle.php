<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\Attribute\AsJsonApiHydrator;
use haddowg\JsonApiBundle\Attribute\AsJsonApiRelations;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass;
use haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass;
use haddowg\JsonApiBundle\Operation\CrudOperationHandler;
use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Serializer\Doctrine\DoctrineRelationshipLoadState;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\ServerFactory;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

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

    /**
     * Tag applied to a standalone {@see \haddowg\JsonApiBundle\Server\RelationsProviderInterface}
     * registered for a type via {@see AsJsonApiRelations} — a type's relations
     * declared with no {@see AbstractResource} (bundle ADR 0026). The tag carries the
     * `type` it declares relations for; {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
     * reads it to wire the type-keyed {@see \haddowg\JsonApiBundle\Server\RelationsRegistry}.
     */
    public const string RELATIONS_TAG = 'haddowg.json_api.relations';

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
                ->arrayNode('servers')
                    ->info('Additional named servers; the top-level base_uri/version define the implicit `default` server. Each named server inherits the top-level value when its own is omitted (ADR 0034).')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('base_uri')->defaultNull()->end()
                            ->scalarNode('version')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $baseUri = $this->stringConfig($config, 'base_uri', '');
        $version = $this->stringConfig($config, 'version', '1.1');

        $builder->setParameter('haddowg_json_api.base_uri', $baseUri);
        $builder->setParameter('haddowg_json_api.version', $version);

        // The full server map (ADR 0034): the implicit `default` server carries the
        // top-level base_uri/version, and each named server from `json_api.servers`
        // inherits the top-level value when its own is omitted. The list of names is
        // a container parameter the ResourceLocatorPass reads to validate resource
        // assignments and bucket per server.
        $servers = $this->serverMap($config, $baseUri, $version);
        $builder->setParameter('haddowg_json_api.servers', \array_keys($servers));

        $container->import(\dirname(__DIR__) . '/config/services.php');

        // One ServerFactory per declared server, plus the ServerProvider that
        // resolves them by name through a service locator. The per-server resource
        // class-strings and serializer/hydrator maps are filled by the pass.
        $this->registerServers($container, $servers);

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
                    'server' => self::serverTag($attribute->server),
                    'entity' => $attribute->entity,
                    'serializer' => $attribute->serializer,
                    'hydrator' => $attribute->hydrator,
                    'operations' => $attribute->operations !== []
                        ? \implode(',', \array_map(static fn(Operation $op): string => $op->value, $attribute->operations))
                        : null,
                ], static fn(mixed $value): bool => $value !== null));
            },
        );

        // Standalone serializer/hydrator capabilities: a class implementing
        // SerializerInterface/HydratorInterface registers for a type with no
        // AbstractResource (ADR 0024). A single class may carry both.
        $builder->registerAttributeForAutoconfiguration(
            AsJsonApiSerializer::class,
            static function (Definition $definition, AsJsonApiSerializer $attribute): void {
                $definition->addTag(self::SERIALIZER_TAG, \array_filter([
                    'type' => $attribute->type,
                    'server' => self::serverTag($attribute->server),
                    'operations' => $attribute->operations !== []
                        ? \implode(',', \array_map(static fn(Operation $op): string => $op->value, $attribute->operations))
                        : null,
                ], static fn(mixed $value): bool => $value !== null));
            },
        );

        $builder->registerAttributeForAutoconfiguration(
            AsJsonApiHydrator::class,
            static function (Definition $definition, AsJsonApiHydrator $attribute): void {
                $definition->addTag(self::HYDRATOR_TAG, \array_filter([
                    'type' => $attribute->type,
                    'server' => self::serverTag($attribute->server),
                ], static fn(mixed $value): bool => $value !== null));
            },
        );

        // Standalone relations: a class implementing RelationsProviderInterface
        // declares a type's relations with no AbstractResource (ADR 0026).
        $builder->registerAttributeForAutoconfiguration(
            AsJsonApiRelations::class,
            static function (Definition $definition, AsJsonApiRelations $attribute): void {
                $definition->addTag(self::RELATIONS_TAG, \array_filter([
                    'type' => $attribute->type,
                    'server' => self::serverTag($attribute->server),
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
     * The full server map: the implicit `default` server plus each named server
     * from `json_api.servers`. A named server inherits the top-level base_uri /
     * version when its own is omitted; a named server may not be literally
     * `default` (that name is reserved for the implicit, top-level server).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, array{base_uri: string, version: string}>
     */
    private function serverMap(array $config, string $baseUri, string $version): array
    {
        $servers = [ServerProvider::DEFAULT_SERVER => ['base_uri' => $baseUri, 'version' => $version]];

        $named = $config['servers'] ?? [];
        if (!\is_array($named)) {
            return $servers;
        }

        foreach ($named as $name => $settings) {
            if ($name === ServerProvider::DEFAULT_SERVER) {
                throw new \LogicException(\sprintf(
                    'The JSON:API server name "%s" is reserved for the implicit server defined by the '
                    . 'top-level base_uri/version; declare other servers under different names.',
                    ServerProvider::DEFAULT_SERVER,
                ));
            }

            $settings = \is_array($settings) ? $settings : [];
            $servers[$name] = [
                'base_uri' => $this->stringConfig($settings, 'base_uri', $baseUri),
                'version' => $this->stringConfig($settings, 'version', $version),
            ];
        }

        return $servers;
    }

    /**
     * Registers one {@see ServerFactory} per declared server (id
     * `haddowg.json_api.server_factory.<name>`) carrying that server's base_uri /
     * version and the shared dependencies; the per-server resource class-strings
     * and serializer/hydrator maps are filled by {@see ResourceLocatorPass}. The
     * {@see ServerProvider} resolves a server by name through a locator over these
     * factories.
     *
     * @param array<string, array{base_uri: string, version: string}> $servers
     */
    private function registerServers(ContainerConfigurator $container, array $servers): void
    {
        $services = $container->services();

        $factoryRefs = [];
        foreach ($servers as $name => $settings) {
            $id = self::serverFactoryId($name);

            $services->set($id, ServerFactory::class)
                ->args([
                    '$resources' => service(ResourceLocator::class),
                    '$responseFactory' => service(\Psr\Http\Message\ResponseFactoryInterface::class),
                    '$streamFactory' => service(\Psr\Http\Message\StreamFactoryInterface::class),
                    '$baseUri' => $settings['base_uri'],
                    '$version' => $settings['version'],
                    '$handler' => service(CrudOperationHandler::class),
                    // Optional: the reference Doctrine predicate, present only on a
                    // Doctrine application that maps an entity, else null (core then
                    // treats every relation as loaded).
                    '$relationshipLoadState' => service(DoctrineRelationshipLoadState::class)->nullOnInvalid(),
                ]);

            $factoryRefs[$name] = service($id);
        }

        $services->set(ServerProvider::class)
            ->args(['$factories' => service_locator($factoryRefs)]);
    }

    /**
     * The container service id for a server's {@see ServerFactory}.
     */
    public static function serverFactoryId(string $server): string
    {
        return 'haddowg.json_api.server_factory.' . $server;
    }

    /**
     * Normalises a tag's `server` value to a comma-joined string (mirroring how
     * `operations` is joined) so it survives the container as a plain scalar; a
     * scalar stays a scalar, a list is comma-joined, and null/empty is filtered out.
     *
     * @param string|list<string>|null $server
     */
    private static function serverTag(string|array|null $server): ?string
    {
        if (\is_array($server)) {
            $server = \implode(',', $server);
        }

        return ($server === null || $server === '') ? null : $server;
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
