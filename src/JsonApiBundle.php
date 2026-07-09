<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Schema\Profile\ProfileInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\Attribute\AsJsonApiHydrator;
use haddowg\JsonApiBundle\Attribute\AsJsonApiRelations;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterArmInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineSortArmInterface;
use haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass;
use haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceDescriptionPass;
use haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass;
use haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceSecurityPass;
use haddowg\JsonApiBundle\DependencyInjection\Compiler\ResponseHeadersPass;
use haddowg\JsonApiBundle\EventListener\ConfiguredExceptionMapper;
use haddowg\JsonApiBundle\EventListener\ExceptionMapperInterface;
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
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

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
     * Tag applied to every {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterArmInterface}.
     * The Doctrine filter handler consults each registered arm (first
     * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterArmInterface::supports()}
     * match wins) to push a custom `FilterInterface` down to DQL before raising
     * `UnsupportedFilter` — the extensible-handler seam (core ADR 0078).
     */
    public const string DOCTRINE_FILTER_ARM_TAG = 'haddowg.json_api.doctrine_filter_arm';

    /**
     * Tag applied to every {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineSortArmInterface}.
     * The Doctrine sort handler consults each registered arm to append the
     * `ORDER BY` for a custom `SortInterface` before raising `UnsupportedSort`.
     */
    public const string DOCTRINE_SORT_ARM_TAG = 'haddowg.json_api.doctrine_sort_arm';

    /**
     * Tag applied to every {@see \haddowg\JsonApiBundle\EventListener\ExceptionMapperInterface}.
     * The {@see \haddowg\JsonApiBundle\EventListener\ExceptionListener} consults them
     * (descending tag `priority`, first non-null result wins) to map a throwable
     * that is NOT a core {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
     * to a JSON:API error document (bundle ADR 0073). The bundle's config-driven
     * {@see \haddowg\JsonApiBundle\EventListener\ConfiguredExceptionMapper} registers
     * at the low `-1000` fallback priority, so an application mapper (default `0`) is
     * always consulted before the `json_api.exceptions` config map.
     */
    public const string EXCEPTION_MAPPER_TAG = 'json_api.exception_mapper';

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

    /**
     * Tag applied to a custom, non-CRUD action handler — a
     * {@see \haddowg\JsonApiBundle\Action\ActionHandlerInterface} registered for a
     * mount type via {@see \haddowg\JsonApiBundle\Attribute\AsJsonApiAction} (bundle
     * ADR 0076). The tag carries the flattened attribute metadata (type/server/path/
     * scope/input/inputType/outputType/security/methods/name);
     * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
     * reads it to wire the {@see \haddowg\JsonApiBundle\Action\ActionRegistry}'s
     * descriptor map + handler locator and the per-server action route descriptors.
     */
    public const string ACTION_TAG = 'haddowg.json_api.action';

    /**
     * Tag applied to an {@see \haddowg\JsonApiBundle\OpenApi\OpenApiFactoryInterface}
     * decorator (design §5, D7 — bundle ADR 0080). The
     * {@see \haddowg\JsonApiBundle\OpenApi\DocumentFactory} consumes the tagged services
     * as a priority-ordered iterator and applies them after the core projection, so an
     * app can mutate the built document (servers/security/tags/examples/anything) before
     * it is served, warmed, or exported. Lower `priority` runs first; the highest gets
     * the final word.
     */
    public const string OPENAPI_FACTORY_TAG = 'haddowg.json_api.openapi_factory';

    /**
     * The bundle's opinionated default `json_api.max_include_depth`: a `?include`
     * may nest at most this many relationship hops from the primary resource
     * unless a resource overrides it with its own `maxIncludeDepth()`. Core itself
     * is unopinionated (null = unlimited); the bundle supplies this cap so a
     * mutual default-include cycle always terminates and a deep `?include` is
     * bounded without per-resource configuration.
     */
    public const int DEFAULT_MAX_INCLUDE_DEPTH = 3;

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
                ->integerNode('max_include_depth')
                    ->info('Cap the nesting depth of a `?include` request (number of relationship hops from the primary resource): a cap of 3 allows `?include=a.b.c` and rejects `a.b.c.d` (400). The default is 3; set 0 for unlimited. A resource\'s own maxIncludeDepth() overrides this server default.')
                    ->defaultValue(self::DEFAULT_MAX_INCLUDE_DEPTH)
                    ->min(0)
                ->end()
                ->booleanNode('strict_query_parameters')
                    ->info('Reject an unrecognized top-level query-parameter family with a 400 (bundle ADR 0055, core ADR 0059). A param is recognized when its base name is a reserved JSON:API family (include/fields/filter/sort/page), a key the primary resource declares, a negotiated profile keyword (relatedQuery/rQ for the Relationship Queries profile, withCount for the Countable profile), or an app-registered custom param. The default is true; set false to restore the old silent-ignore behaviour.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('exceptions')
                    ->info('Map an exception class (FQCN) to an HTTP status; a thrown instance renders as a JSON:API error with that status, reason-phrase title, and (in debug) its message as detail. A core JsonApiExceptionInterface always renders natively and is never overridden by this map. For richer errors (custom source/meta), implement ExceptionMapperInterface (bundle ADR 0073). Default empty.')
                    ->useAttributeAsKey('class')
                    ->integerPrototype()->end()
                ->end()
                ->arrayNode('pagination')
                    ->info('Tuning for the server\'s default page-based paginator. See core docs/pagination.md.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_per_page')
                            ->info('Cap the client-controlled page[size]/page[limit] of the server default paginator (closes a page-size DoS vector). The default is core\'s PagePaginator::DEFAULT_MAX_PER_PAGE (100); set 0 to disable the cap (unlimited). A resource with its own pagination() sets its own cap via withMaxPerPage().')
                            ->defaultValue(\haddowg\JsonApi\Pagination\PagePaginator::DEFAULT_MAX_PER_PAGE)
                            ->min(0)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->info('Tuning for the reference Doctrine data provider.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('window_functions')
                            ->info('Use SQL window functions (ROW_NUMBER/COUNT OVER) for the bounded windowed-include batch — ONE native query per relation fetches only ~limit rows per parent and the REAL per-parent total, instead of materialising every parent\'s whole related set. Default true. Requires MySQL>=8, MariaDB>=10.2, SQLite>=3.25, or any PostgreSQL. On an older engine this throws a 500 at the first windowed include — set false to use the per-parent bounded fallback instead (M bounded LIMIT queries per relation, no window functions).')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('defaults')
                    ->info('Global default response headers applied to a resource that declares none (bundle ADR 0054). A resource-level cacheHeaders / deprecation overrides (and merges with) these.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('cache_headers')
                            ->info('Default HTTP cache directives for safe (GET) successful reads: max_age/s_maxage/public/private/no_cache/must_revalidate/vary. Applied only when a resource declares no cacheHeaders of its own.')
                            ->children()
                                ->integerNode('max_age')->defaultNull()->end()
                                ->integerNode('s_maxage')->defaultNull()->end()
                                ->booleanNode('public')->defaultNull()->end()
                                ->booleanNode('private')->defaultNull()->end()
                                ->booleanNode('no_cache')->defaultNull()->end()
                                ->booleanNode('must_revalidate')->defaultNull()->end()
                                ->arrayNode('vary')->scalarPrototype()->end()->end()
                            ->end()
                        ->end()
                        ->scalarNode('deprecation')
                            ->info('Default Deprecation header (IETF Deprecation-header draft): a bool (true => bare header) or a date string. Applied when a resource declares no deprecation.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('sunset')
                            ->info('Default RFC 8594 Sunset HTTP-date. Applied when a resource declares no sunset.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('sunset_link')
                            ->info('Default URI for the companion Link: <uri>; rel="sunset" (emitted only when a sunset is set).')
                            ->defaultNull()
                        ->end()
                    ->end()
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
                ->arrayNode('atomic_operations')
                    ->info('The JSON:API Atomic Operations extension (https://jsonapi.org/ext/atomic): a batch of write operations applied in order, all-or-nothing, within one request. Opt-in (default off): when enabled, each server gains a POST {path} endpoint that negotiates the atomic ext, parses atomic:operations, and runs them transactionally with local-id (lid) cross-references.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Emit the Atomic Operations endpoint per server. Default false.')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('path')
                            ->info('The path the per-server Atomic Operations endpoint is served at (POST). Default /operations.')
                            ->defaultValue('/operations')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('profiles')
                    ->info('The JSON:API profiles every server recognizes: each profile\'s URI is advertised (Content-Type `profile` param + `jsonapi.profile`) when a client negotiates it, and the profile\'s opt-in query family is parsed only under that negotiation. Each entry is a ProfileInterface class-string. Default: the three built-ins — CursorPaginationProfile, CountableProfile, RelationshipQueriesProfile — in that order (the order the generated OpenAPI `jsonapi.profile` enum lists them, and the order that must match across framework adapters for byte-parity; core ADR 0131). Trim an entry to stop recognizing and advertising that profile — its OpenAPI parameters (`?withCount`, `relatedQuery`, the cursor page marker) disappear with it; add your own ProfileInterface class to recognize a custom profile.')
                    ->scalarPrototype()->end()
                    ->defaultValue(ServerFactory::DEFAULT_PROFILES)
                ->end()
                ->append($this->openApiNode())
            ->end();
    }

    /**
     * The `json_api.openapi.*` configuration subtree (design §6, D8/D13/D15/D16): the
     * OpenAPI document generation, serving + exposure, info / servers / security /
     * tags metadata, and the cache-warmer's optional static-file output. The UI
     * subtree is declared here (forward-compatibly) but only consumed in Slice 5.
     */
    private function openApiNode(): \Symfony\Component\Config\Definition\Builder\NodeDefinition
    {
        $treeBuilder = new \Symfony\Component\Config\Definition\Builder\TreeBuilder('openapi');
        /** @var \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->info('OpenAPI 3.1 document generation, serving and export (G1–G6).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Whether OpenAPI generation is available at all. When false, no document routes are emitted and the cache warmer skips warming (the CLI export still works). Default true.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('expose_in_prod')
                    ->info('Expose the document HTTP routes outside kernel.debug (D9). Routes are auto-exposed in debug; set true to also serve them in prod. Default false.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('describedby')
                    ->info('Stamp a top-level links.describedby onto every JSON:API response pointing at the served OpenAPI document for the request\'s server (JSON:API 1.1). Only takes effect when the document routes are actually served (generation enabled + the expose gate). Default true.')
                    ->defaultTrue()
                ->end()
                ->enumNode('multi_server')
                    ->info('per_server (default): one document per server, served at /{server}/docs.json. combined: a single document spanning every server at the json path only (D5).')
                    ->values(['per_server', 'combined'])
                    ->defaultValue('per_server')
                ->end()
                ->enumNode('enum_value_descriptions')
                    ->info('How a backed enum\'s per-value descriptions are surfaced (D16): both (markdown table + x-enum-* extensions), extensions (only the extensions), or description (only the markdown table). Default both.')
                    ->values(['both', 'extensions', 'description'])
                    ->defaultValue('both')
                ->end()
                ->scalarNode('public_path')
                    ->info('Also emit a fully static <server>.json (+ .yaml when symfony/yaml is installed) into this directory at cache:warmup (D17), so a web server/CDN can serve the document with zero PHP. Null = controller-only (served from the cache dir).')
                    ->defaultNull()
                ->end()
                ->arrayNode('json')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')
                            ->info('The path the default server\'s document is served at. Default /docs.json.')
                            ->defaultValue('/docs.json')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('json_schema')
                    ->info('Serve the standalone per-type JSON Schema 2020-12 documents over HTTP, aggregated into one object keyed by type, alongside the OpenAPI document (the source for a client codegen\'s opt-in validation seam). Behind the same expose gate as the document. The CLI export (json-api:json-schema:export) stays available regardless.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Register the JSON Schema HTTP routes. Default true (still subject to the expose gate). The route serves GET /schemas.json (+ /{server}/schemas.json per server).')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('path')
                            ->info('The path the default server\'s aggregate schema document is served at. Default /schemas.json.')
                            ->defaultValue('/schemas.json')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ui')
                    ->info('The Swagger UI / ReDoc documentation viewer (design D6, ADR 0079): a single config-driven route rendering plain CDN-linked HTML, behind the same expose gate as the document plus `ui.enabled`.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->info('Serve the viewer route. Default true (still subject to the expose gate).')->defaultTrue()->end()
                        ->enumNode('renderer')->info('swagger (default) or redoc — one, not both.')->values(['swagger', 'redoc'])->defaultValue('swagger')->end()
                        ->scalarNode('path')->info('The viewer route path. Default /docs.')->defaultValue('/docs')->end()
                        ->scalarNode('cdn')->info('Override the pinned CDN asset origin (self-host / air-gap). Null = the bundle-pinned jsDelivr URL.')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('info')
                    ->info('The OpenAPI info block (G4/G5). Title/version default to JSON:API / 1.0.0 per server when omitted.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('title')->defaultNull()->end()
                        ->scalarNode('version')->defaultNull()->end()
                        ->scalarNode('description')->defaultNull()->end()
                        ->arrayNode('contact')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('name')->defaultNull()->end()
                                ->scalarNode('url')->defaultNull()->end()
                                ->scalarNode('email')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('license')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('name')->defaultNull()->end()
                                ->scalarNode('identifier')->defaultNull()->end()
                                ->scalarNode('url')->defaultNull()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('servers')
                    ->info('Override the advertised OAS servers list. Null/empty = derive one server from each JSON:API server\'s base URI (D2). A non-empty list replaces that derivation document-wide.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('url')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('description')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->info('Named security schemes + the document-level default requirement applied to operations carrying a security expression (D8). The authz expression itself is never parsed for scheme semantics.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('schemes')
                            ->info('Named securitySchemes (components.securitySchemes). type: http|apiKey|oauth2|openIdConnect|bearer (bearer is shorthand for http+bearer).')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('type')->isRequired()->end()
                                    ->scalarNode('description')->defaultNull()->end()
                                    ->scalarNode('scheme')->defaultNull()->end()
                                    ->scalarNode('bearerFormat')->defaultNull()->end()
                                    ->scalarNode('in')->defaultNull()->end()
                                    ->scalarNode('apiKeyName')->info('The header/query/cookie key name for an apiKey scheme.')->defaultNull()->end()
                                    ->scalarNode('openIdConnectUrl')->defaultNull()->end()
                                    ->arrayNode('flows')
                                        ->info('The supported OAuth2 flows (only for type: oauth2). Each flow: authorizationUrl/tokenUrl/refreshUrl as its grant requires + scopes (name => description).')
                                        ->children()
                                            ->append($this->oauthFlowNode('implicit'))
                                            ->append($this->oauthFlowNode('password'))
                                            ->append($this->oauthFlowNode('clientCredentials'))
                                            ->append($this->oauthFlowNode('authorizationCode'))
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('default_requirement')
                            ->info('The scheme name(s) required by a secured operation by default. Each entry: a bare scheme name (no scopes), or {name, scopes:[...]}.')
                            ->beforeNormalization()
                                ->ifString()->then(static fn(string $value): array => [$value])
                            ->end()
                            ->arrayPrototype()
                                ->beforeNormalization()
                                    ->ifString()->then(static fn(string $value): array => ['name' => $value])
                                ->end()
                                ->children()
                                    ->scalarNode('name')->isRequired()->end()
                                    ->arrayNode('scopes')->scalarPrototype()->end()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('externalDocs')
                    ->info('Document-level external documentation link.')
                    ->children()
                        ->scalarNode('url')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('description')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('tags')
                    ->info('Top-level tag DEFINITIONS (authoritative; D15). Any tag referenced by a resource/action but undefined here is auto-synthesized (name only). Config order is the emit order.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('description')->defaultNull()->end()
                            ->arrayNode('externalDocs')
                                ->children()
                                    ->scalarNode('url')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('description')->defaultNull()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $root;
    }

    /**
     * One OAuth2 flow node (implicit/password/clientCredentials/authorizationCode) under
     * a scheme's `flows` graph (§4.6, D8). The member URLs a flow needs depend on its
     * grant (authorizationUrl for implicit/authorizationCode, tokenUrl for the token
     * grants); the resolver maps whatever is present, and a flow node only contributes a
     * flow when at least one member is set.
     */
    private function oauthFlowNode(string $name): \Symfony\Component\Config\Definition\Builder\NodeDefinition
    {
        $treeBuilder = new \Symfony\Component\Config\Definition\Builder\TreeBuilder($name);
        /** @var \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $node */
        $node = $treeBuilder->getRootNode();

        $node
            ->children()
                ->scalarNode('authorizationUrl')->defaultNull()->end()
                ->scalarNode('tokenUrl')->defaultNull()->end()
                ->scalarNode('refreshUrl')->defaultNull()->end()
                ->arrayNode('scopes')
                    ->info('Available scopes for this flow: scope name => human description.')
                    ->useAttributeAsKey('name')
                    ->scalarPrototype()->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $baseUri = $this->stringConfig($config, 'base_uri', '');
        $version = $this->stringConfig($config, 'version', '1.1');
        $maxPerPage = $this->maxPerPageConfig($config);
        $maxIncludeDepth = $this->maxIncludeDepthConfig($config);
        $strictQueryParameters = $this->strictQueryParametersConfig($config);
        $windowFunctions = $this->windowFunctionsConfig($config);
        $profiles = $this->profilesConfig($config);

        [$atomicEnabled, $atomicPath] = $this->atomicOperationsConfig($config);

        $builder->setParameter('haddowg_json_api.base_uri', $baseUri);
        $builder->setParameter('haddowg_json_api.version', $version);
        $builder->setParameter('haddowg_json_api.pagination.max_per_page', $maxPerPage);
        $builder->setParameter('haddowg_json_api.max_include_depth', $maxIncludeDepth);
        $builder->setParameter('haddowg_json_api.doctrine.window_functions', $windowFunctions);
        // The Atomic Operations endpoint config (opt-in, default off): the route loader
        // reads these to emit one POST {path} route per server when enabled.
        $builder->setParameter('haddowg_json_api.atomic_operations.enabled', $atomicEnabled);
        $builder->setParameter('haddowg_json_api.atomic_operations.path', $atomicPath);
        // The exception-class => HTTP-status map (bundle ADR 0073) the config-driven
        // ConfiguredExceptionMapper reads to map a thrown app/third-party exception
        // to a JSON:API error. Default empty (no config mappings).
        $builder->setParameter('json_api.exceptions', $this->exceptionsConfig($config));

        // The full server map (ADR 0034): the implicit `default` server carries the
        // top-level base_uri/version, and each named server from `json_api.servers`
        // inherits the top-level value when its own is omitted. The list of names is
        // a container parameter the ResourceLocatorPass reads to validate resource
        // assignments and bucket per server.
        $servers = $this->serverMap($config, $baseUri, $version);
        $builder->setParameter('haddowg_json_api.servers', \array_keys($servers));

        $container->import(\dirname(__DIR__) . '/config/services.php');

        // The config-driven exception mapper (bundle ADR 0073): maps a thrown
        // app/third-party exception named in `json_api.exceptions` to a status-keyed
        // JSON:API error. Tagged at the low `-1000` fallback priority so an
        // application's own ExceptionMapperInterface (default `0`) is consulted
        // first. The parameter is set above (default empty).
        $container->services()
            ->set(ConfiguredExceptionMapper::class)
            ->args([
                '$map' => '%json_api.exceptions%',
                '$debug' => '%kernel.debug%',
            ])
            ->tag(self::EXCEPTION_MAPPER_TAG, ['priority' => -1000]);

        // One ServerFactory per declared server, plus the ServerProvider that
        // resolves them by name through a service locator. The per-server resource
        // class-strings and serializer/hydrator maps are filled by the pass.
        $this->registerServers($container, $servers, $maxPerPage, $maxIncludeDepth, $strictQueryParameters, $profiles);

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

        // Localizable/overridable error catalogue (bundle ADR 0115): bind a resolver
        // over the Symfony translator so an app can localize or rebrand any error's
        // title/detail per its stable code via translation files (the `jsonapi_errors`
        // domain). Gated on the concrete symfony/translation component (not merely the
        // contracts, which are always present) so the `translator` service it depends on
        // actually exists; without it the ServerFactory arg resolves null (nullOnInvalid)
        // and core renders its inline English copy, byte-identical to today.
        if (\class_exists(\Symfony\Component\Translation\Translator::class)) {
            $container->services()
                ->set(\haddowg\JsonApiBundle\Server\TranslatorErrorMessageResolver::class)
                ->args(['$translator' => \Symfony\Component\DependencyInjection\Loader\Configurator\service('translator')]);
        }

        // Declarative resource authorization (bundle ADR 0043): the type-keyed
        // ResourceSecurityRegistry + the ResourceSecuritySubscriber that evaluates a
        // type's security expression at the lifecycle hooks. Registered only when
        // symfony/security-core (the AuthorizationChecker) and
        // symfony/expression-language (the Expression syntax) are installed — both
        // `suggest` dependencies; absent either, a declared `security` is inert. The
        // ResourceSecurityPass (added in build()) fills the registry's map and is
        // itself a no-op when the registry is undefined.
        if (\interface_exists(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class)
            && \class_exists(\Symfony\Component\ExpressionLanguage\Expression::class)
        ) {
            $services = $container->services();

            $services->set(\haddowg\JsonApiBundle\Security\ResourceSecurityRegistry::class)
                ->args(['$expressions' => []]);

            // security.authorization_checker exists only when a firewall is
            // configured (SecurityBundle), not merely when symfony/security-core is
            // on the classpath. So it is injected ->nullOnInvalid() and the
            // subscriber is a no-op when absent — a JSON:API app without a firewall
            // keeps working, and a declared `security` is inert until one is wired.
            $services->set(\haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber::class)
                ->args([
                    '$authorizationChecker' => service('security.authorization_checker')->nullOnInvalid(),
                    '$registry' => service(\haddowg\JsonApiBundle\Security\ResourceSecurityRegistry::class),
                ])
                ->tag('kernel.event_subscriber');
        }

        // Declarative response headers (bundle ADR 0054): the type-keyed
        // ResponseHeadersRegistry seeded with the global `json_api.defaults`
        // cache/deprecation defaults, plus the ResponseHeadersListener (wired in
        // services.php) that emits them per request. The per-type map is filled by
        // the ResponseHeadersPass (added in build()) from each resource's
        // #[AsJsonApiResource(cacheHeaders/deprecation/sunset)]; the registry is
        // always present (no optional dependency — these are pure HTTP headers).
        [$defaultCache, $defaultDeprecation] = $this->responseHeaderDefaults($config);
        $container->services()
            ->set(\haddowg\JsonApiBundle\Http\ResponseHeadersRegistry::class)
            ->args([
                '$byType' => [],
                '$defaultCache' => $defaultCache,
                '$defaultDeprecation' => $defaultDeprecation,
            ]);

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

        // Any app service implementing a custom-filter / custom-sort arm is auto-tagged,
        // so the Doctrine handlers consult it to push down a custom FilterInterface /
        // SortInterface the built-ins don't recognise (the extensible-handler seam).
        $builder->registerForAutoconfiguration(DoctrineFilterArmInterface::class)
            ->addTag(self::DOCTRINE_FILTER_ARM_TAG);

        $builder->registerForAutoconfiguration(DoctrineSortArmInterface::class)
            ->addTag(self::DOCTRINE_SORT_ARM_TAG);

        // Any app service implementing the exception-mapper seam is auto-tagged, so
        // it is consulted by the ExceptionListener (before the config map) for a
        // throwable that is not a core JsonApiExceptionInterface (bundle ADR 0073).
        $builder->registerForAutoconfiguration(ExceptionMapperInterface::class)
            ->addTag(self::EXCEPTION_MAPPER_TAG);

        // Any app service implementing the OpenAPI decorator seam is auto-tagged, so the
        // DocumentFactory applies it (priority-ordered) over the built document for every
        // build path — warmer, controller lazy-build, and CLI export (bundle ADR 0080).
        $builder->registerForAutoconfiguration(\haddowg\JsonApiBundle\OpenApi\OpenApiFactoryInterface::class)
            ->addTag(self::OPENAPI_FACTORY_TAG);

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
                    // The exposed operation allow-list: an explicit `operations` list,
                    // or the `readOnly` shorthand restricting the type to the two fetch
                    // operations. The attribute constructor forbids declaring both, so
                    // at most one of these branches contributes a non-null value.
                    'operations' => self::operationsTag($attribute),
                    // The declarative authorization expressions (bundle ADR 0043),
                    // recorded as scalar tag attributes the ResourceSecurityPass reads
                    // into the type-keyed ResourceSecurityRegistry. Inert (never read)
                    // when symfony/security-core is absent.
                    'security_default' => $attribute->security,
                    'security_create' => $attribute->securityCreate,
                    'security_update' => $attribute->securityUpdate,
                    'security_delete' => $attribute->securityDelete,
                    'security_read' => $attribute->securityRead,
                    'security_list' => $attribute->securityList,
                    // The declarative response-header config (bundle ADR 0054) —
                    // cache directives + deprecation/sunset headers — JSON-encoded
                    // into a single scalar tag attribute the ResponseHeadersPass
                    // decodes into the type-keyed ResponseHeadersRegistry. A nested
                    // structure (the per-operation cache overrides) does not survive
                    // as a flat tag attribute, so it is carried as one JSON string.
                    'response_headers' => self::responseHeadersTag($attribute),
                    // The OpenAPI tag refs (design §4.7, D15), comma-joined to survive
                    // the container as a plain scalar (mirroring `operations`); the
                    // ResourceLocatorPass parses them back for the OpenAPI MetadataSource.
                    'tags' => self::tagsTag($attribute->tags),
                    // The OpenAPI description overrides (bundle ADR 0092): the
                    // resource-object schema description as a scalar, and the
                    // per-operation overrides JSON-encoded into a single scalar (a
                    // nested map does not survive as a flat tag attribute, like
                    // `response_headers`). The ResourceDescriptionPass reads both into
                    // the type-keyed ResourceDescriptionRegistry the MetadataSource layers
                    // beneath the resource's own method hooks.
                    'description' => $attribute->description,
                    'operation_descriptions' => self::operationDescriptionsTag($attribute),
                    // The per-operation OpenAPI response declarations (typed response
                    // objects): the five create/update/delete/fetchOne/fetchCollection
                    // sets JSON-encoded into a single scalar tag attribute the
                    // ResourceLocatorPass decodes into the route descriptor for the
                    // OpenAPI MetadataSource (a nested map does not survive as a flat tag
                    // attribute, like `operation_descriptions`).
                    'responses' => self::responsesTag($attribute),
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
                    // Standalone-type OpenAPI tag refs (design §4.7); empty = the
                    // humanized-type default resolved by the MetadataSource.
                    'tags' => self::tagsTag($attribute->tags),
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

        // Custom, non-CRUD actions: a standalone ActionHandlerInterface class
        // declared with #[AsJsonApiAction] hangs off a mount type under the
        // reserved `-actions` segment (bundle ADR 0076). The attribute's enums and
        // method list are flattened to plain scalar tag attributes (enum case names,
        // a comma-joined method list — mirroring how `operations` is encoded) so the
        // ResourceLocatorPass can read them from the compiled container; the
        // decoupled-document defaults (inputType/outputType) are resolved there.
        $builder->registerAttributeForAutoconfiguration(
            AsJsonApiAction::class,
            static function (Definition $definition, AsJsonApiAction $attribute): void {
                $definition->addTag(self::ACTION_TAG, \array_filter([
                    'type' => $attribute->type,
                    'server' => self::serverTag($attribute->server),
                    'path' => $attribute->path,
                    'scope' => $attribute->scope->name,
                    'input' => $attribute->input->name,
                    'inputType' => $attribute->inputType,
                    // The declared success-response set (core ADR 0127), JSON-encoded as a
                    // list of {kind, type?, jobType?}; absent = the ResourceLocatorPass
                    // defaults it to a 200 resource document of the mount type.
                    'responds' => self::actionRespondsTag($attribute),
                    'security' => $attribute->security,
                    'methods' => $attribute->methods !== [] ? \implode(',', $attribute->methods) : null,
                    'name' => $attribute->name,
                    // The OpenAPI tag refs (design §4.7); empty = inherit the mount
                    // type's resource tags, resolved by the MetadataSource.
                    'tags' => self::tagsTag($attribute->tags),
                    // Only carried when set: expose the action as a security-aware
                    // `links` member on the mount type's resources (resource scope
                    // only; the attribute constructor rejects a collection scope).
                    'asLink' => $attribute->asLink ? true : null,
                ], static fn(mixed $value): bool => $value !== null));
            },
        );

        // OpenAPI document generation, serving, warming + export (Slice 4 stage B,
        // bundle ADRs 0077/0078): resolve json_api.openapi.* into the typed config, set
        // its parameters, and register the document/JSON-schema factories, the cache
        // warmer, the serving controller, the docs route loader and the two export
        // commands. The MetadataSource (registered in services.php) is given the
        // per-server document config (info / servers / security / tags) here.
        $this->registerOpenApi($config, $container, $builder, \array_keys($servers));
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ResourceLocatorPass());
        $container->addCompilerPass(new DoctrineEntityMapPass());
        $container->addCompilerPass(new ResourceSecurityPass());
        $container->addCompilerPass(new ResponseHeadersPass());
        $container->addCompilerPass(new ResourceDescriptionPass());
    }

    /**
     * Wires the OpenAPI subsystem from `json_api.openapi.*` (design §3, §6,
     * D9/D13/D17; bundle ADRs 0077/0078):
     *  - resolves the config into a typed {@see \haddowg\JsonApiBundle\OpenApi\Config\OpenApiConfig};
     *  - feeds the per-server document config (info / servers / security / tags) into
     *    the already-registered {@see \haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource};
     *  - registers the {@see \haddowg\JsonApiBundle\OpenApi\DocumentFactory} +
     *    {@see \haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory} (configured with the
     *    enum-description mode), the {@see \haddowg\JsonApiBundle\OpenApi\ArtifactStore},
     *    the optional {@see \haddowg\JsonApiBundle\OpenApi\DocumentWarmer}, the serving
     *    {@see \haddowg\JsonApiBundle\Controller\OpenApiController}, the docs route
     *    loader, and the two export commands.
     *
     * The route loader applies the expose gate (`kernel.debug || expose_in_prod`), so
     * no document route exists when generation is disabled or exposure is not allowed
     * — while the CLI export and the warmer stay available regardless (D9).
     *
     * @param array<string, mixed> $config
     * @param list<string>         $serverNames
     */
    private function registerOpenApi(array $config, ContainerConfigurator $container, ContainerBuilder $builder, array $serverNames): void
    {
        $openApiConfig = (new \haddowg\JsonApiBundle\OpenApi\Config\OpenApiConfigResolver())->resolve($config, $serverNames);

        $services = $container->services();

        // The resolved openapi config as a pure-scalar parameter: the compiled
        // container cannot dump the OAS value objects (info / servers / security / tag
        // VOs) as service arguments, so the ServerDocumentConfigProvider rebuilds them
        // at runtime from this scalar array (the same OpenApiConfigResolver, called on
        // boot rather than at compile).
        $openApiScalarConfig = \is_array($config['openapi'] ?? null) ? $config['openapi'] : [];
        $builder->setParameter('haddowg_json_api.openapi', $openApiScalarConfig);

        $services->set(\haddowg\JsonApiBundle\OpenApi\Config\OpenApiConfigResolver::class);

        // The per-server ServerDocumentConfig map, produced on boot and injected into
        // the MetadataSource (registered in services.php with an empty default) via a
        // service factory — a service may resolve to an array value.
        $services->set(\haddowg\JsonApiBundle\OpenApi\Config\ServerDocumentConfigProvider::class)
            ->args([
                '$resolver' => service(\haddowg\JsonApiBundle\OpenApi\Config\OpenApiConfigResolver::class),
                '$openApiConfig' => '%haddowg_json_api.openapi%',
                '$servers' => $serverNames,
            ]);

        // A factory service that resolves to the array<server, ServerDocumentConfig>
        // (a service may resolve to a non-object value via its factory). It is
        // injected as the MetadataSource's $configByServer; its declared class is the
        // provider (harmless metadata — the factory return is the real value).
        $services->set('haddowg.json_api.openapi.server_document_config', \haddowg\JsonApiBundle\OpenApi\Config\ServerDocumentConfigProvider::class)
            ->factory([service(\haddowg\JsonApiBundle\OpenApi\Config\ServerDocumentConfigProvider::class), 'map']);

        $builder->getDefinition(\haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource::class)
            ->setArgument('$configByServer', new \Symfony\Component\DependencyInjection\Reference('haddowg.json_api.openapi.server_document_config'));

        $enumMode = $openApiConfig->enumDescriptionMode;

        // The per-server document + per-type JSON-Schema factories, both configured
        // with the enum-description mode so a backed enum surfaces identically.
        $services->set(\haddowg\JsonApiBundle\OpenApi\DocumentFactory::class)
            ->args([
                '$metadata' => service(\haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource::class),
                '$enumDescriptionMode' => $enumMode,
                // The wholesale-customisation decorators (design §5, ADR 0080), composed
                // priority-ordered (lower first); applied after projection on every build
                // path — warmer, controller lazy-build, CLI export.
                '$decorators' => tagged_iterator(self::OPENAPI_FACTORY_TAG),
            ]);

        $services->set(\haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory::class)
            ->args([
                '$servers' => service(ServerProvider::class),
                '$types' => service(\haddowg\JsonApiBundle\Server\TypeMetadataResolver::class),
                '$descriptors' => service(\haddowg\JsonApiBundle\Server\RouteDescriptorRegistry::class),
                '$enumDescriptionMode' => $enumMode,
            ]);

        // The shared cache-artifact store the warmer writes and the controller reads.
        $services->set(\haddowg\JsonApiBundle\OpenApi\ArtifactStore::class)
            ->args(['$cacheDir' => '%kernel.cache_dir%']);

        // The optional cache warmer (D17): pre-builds each server's document + JSON
        // Schemas at cache:warmup, and (when public_path is set) a static .json/.yaml.
        // isOptional() === true, so a docs failure never breaks a deploy.
        $services->set(\haddowg\JsonApiBundle\OpenApi\DocumentWarmer::class)
            ->args([
                '$documents' => service(\haddowg\JsonApiBundle\OpenApi\DocumentFactory::class),
                '$schemas' => service(\haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory::class),
                '$store' => service(\haddowg\JsonApiBundle\OpenApi\ArtifactStore::class),
                '$servers' => $serverNames,
                '$enabled' => $openApiConfig->enabled,
                '$combined' => $openApiConfig->combined,
                '$publicPath' => $openApiConfig->publicPath,
                '$logger' => service('logger')->nullOnInvalid(),
            ])
            ->tag('kernel.cache_warmer');

        // The serving controller: serves the pre-built artifact, lazy-builds in dev.
        $services->set(\haddowg\JsonApiBundle\Controller\OpenApiController::class)
            ->args([
                '$documents' => service(\haddowg\JsonApiBundle\OpenApi\DocumentFactory::class),
                '$store' => service(\haddowg\JsonApiBundle\OpenApi\ArtifactStore::class),
                '$debug' => '%kernel.debug%',
                '$combined' => $openApiConfig->combined,
            ])
            ->public()
            ->tag('controller.service_arguments');

        // The documentation viewer controller (design D6): renders Swagger UI or ReDoc
        // (one, per config) pointed at the configured json path; CDN-linked, overridable.
        $services->set(\haddowg\JsonApiBundle\Controller\OpenApiUiController::class)
            ->args([
                '$urlGenerator' => service('router'),
                '$renderer' => $openApiConfig->ui->renderer,
                '$jsonPath' => $openApiConfig->jsonPath,
                '$cdn' => $openApiConfig->ui->cdn,
            ])
            ->public()
            ->tag('controller.service_arguments');

        // The JSON Schema serving controller: serves the pre-built aggregate artifact
        // (the per-type JSON Schemas keyed by type), lazy-builds in dev — alongside the
        // OpenAPI document, as the source for a client codegen's validation seam.
        $services->set(\haddowg\JsonApiBundle\Controller\JsonSchemaController::class)
            ->args([
                '$schemas' => service(\haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory::class),
                '$store' => service(\haddowg\JsonApiBundle\OpenApi\ArtifactStore::class),
                '$debug' => '%kernel.debug%',
                '$combined' => $openApiConfig->combined,
            ])
            ->public()
            ->tag('controller.service_arguments');

        // The docs route loader: emits the document routes only when generation is
        // enabled AND the expose gate passes (kernel.debug || expose_in_prod). The
        // gate is OR-ed inside load() because %kernel.debug% is a parameter resolved
        // at container build, not known here at compile.
        $services->set(\haddowg\JsonApiBundle\Routing\OpenApiRouteLoader::class)
            ->args([
                '$servers' => $serverNames,
                '$enabled' => $openApiConfig->enabled,
                '$debug' => '%kernel.debug%',
                '$exposeInProd' => $openApiConfig->exposeInProd,
                '$combined' => $openApiConfig->combined,
                '$jsonPath' => $openApiConfig->jsonPath,
                '$uiEnabled' => $openApiConfig->ui->enabled,
                '$uiPath' => $openApiConfig->ui->path,
                '$jsonSchemaEnabled' => $openApiConfig->jsonSchemaEnabled,
                '$jsonSchemaPath' => $openApiConfig->jsonSchemaPath,
            ])
            ->tag('routing.loader');

        // The describedby stamper (D14): a kernel.view listener that, before the
        // ViewListener renders, points every JSON:API response's top-level
        // links.describedby at the served OpenAPI document (JSON:API 1.1). It runs only
        // when generation is enabled and describedby is on; the link is omitted anyway
        // when the document route is not registered (the expose gate closed). Higher
        // priority than the ViewListener (priority 0) so it augments the stashed VO first.
        $services->set(\haddowg\JsonApiBundle\EventListener\DescribedbyListener::class)
            ->args([
                '$urlGenerator' => service('router'),
                '$enabled' => $openApiConfig->enabled && $openApiConfig->describedby,
                '$combined' => $openApiConfig->combined,
            ])
            ->tag('kernel.event_listener', ['event' => 'kernel.view', 'method' => 'onKernelView', 'priority' => 16]);

        // The two export commands (D13) — always available (independent of exposure).
        $services->set(\haddowg\JsonApiBundle\Command\OpenApiExportCommand::class)
            ->args(['$documents' => service(\haddowg\JsonApiBundle\OpenApi\DocumentFactory::class)])
            ->tag('console.command');

        $services->set(\haddowg\JsonApiBundle\Command\JsonSchemaExportCommand::class)
            ->args(['$schemas' => service(\haddowg\JsonApiBundle\OpenApi\JsonSchemaFactory::class)])
            ->tag('console.command');
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
     * @param list<class-string<ProfileInterface>>                     $profiles the JSON:API profiles every server registers, in order (bundle ADR 0117)
     */
    private function registerServers(ContainerConfigurator $container, array $servers, int $maxPerPage, int $maxIncludeDepth, bool $strictQueryParameters, array $profiles): void
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
                    // The page-size cap (json_api.pagination.max_per_page) the built-in
                    // default paginator clamps page[size]/page[limit] to; 0 disables it
                    // (no server default paginator unless a custom one is registered).
                    '$maxPerPage' => $maxPerPage,
                    // The server default `?include` nesting cap
                    // (json_api.max_include_depth, default 3); 0 disables it (unlimited).
                    // A resource's own maxIncludeDepth() overrides it per type.
                    '$maxIncludeDepth' => $maxIncludeDepth,
                    // Reject an unrecognized top-level query-parameter family with a
                    // 400 (json_api.strict_query_parameters, default true; bundle ADR
                    // 0055, core ADR 0059). False restores the old silent-ignore.
                    '$strictQueryParameters' => $strictQueryParameters,
                    // Optional custom server-default paginators (e.g. a CursorPaginator),
                    // resolved by-server-first then generic: an app may register a
                    // PaginatorInterface service for THIS server, or one for all servers
                    // (or neither, falling back to the built-in capped PagePaginator).
                    '$serverDefaultPaginator' => service(self::defaultPaginatorId($name))->nullOnInvalid(),
                    '$defaultPaginator' => service(self::defaultPaginatorId())->nullOnInvalid(),
                    // Optional: the reference Doctrine predicate, present only on a
                    // Doctrine application that maps an entity, else null (core then
                    // treats every relation as loaded).
                    '$relationshipLoadState' => service(DoctrineRelationshipLoadState::class)->nullOnInvalid(),
                    // The per-request count seam holder (bundle ADR 0052): a stable
                    // service threaded into the memoized Server once, whose batched
                    // backing the handler swaps per read so the render emits
                    // meta.total for ?withCount-named countable relations. Always
                    // present (it is provider-agnostic — the batch fill is the
                    // provider's job), so no nullOnInvalid().
                    '$relationshipCount' => service(\haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipCount::class),
                    // The per-request relationship-window seam holder (bundle ADR
                    // 0053): the page-1 windowing twin of the count holder, threaded
                    // into the memoized Server once so the handler can swap each
                    // profile read's windowed pages into the render. Always present
                    // (provider-agnostic — the batch fill is the provider's job).
                    '$relationshipPagination' => service(\haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipPagination::class),
                    // The per-request relationship-LINKAGE seam holder (bundle ADR
                    // 0086): threaded into the memoized Server once so the handler can
                    // swap each profile read's windowed linkage into the render WITHOUT
                    // the batcher writing it onto the parent property. Always present
                    // (provider-agnostic — the batch fill is the provider's job).
                    '$relationshipLinkage' => service(\haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipLinkage::class),
                    // The out-of-band resource-link contributor (bundle ADR 0091): a
                    // single shared service threaded onto every server's memoized Server,
                    // contributing a security-aware link for each #[AsJsonApiAction(asLink:
                    // true)] action of the type being rendered, scoped to the request's
                    // server. Its per-server link map is filled by the ResourceLocatorPass.
                    '$resourceLinkContributor' => service(\haddowg\JsonApiBundle\Action\ActionLinkContributor::class),
                    // The error-message resolver (bundle ADR 0115): a translator-backed
                    // resolver that localizes/overrides each error's title/detail by its
                    // stable code. Null when symfony/translation is absent (the service is
                    // not registered), so core renders its inline English copy.
                    '$errorMessageResolver' => service(\haddowg\JsonApiBundle\Server\TranslatorErrorMessageResolver::class)->nullOnInvalid(),
                    // This server's name + the dispatcher the serving bridge fires
                    // the bundle ServingEvent through (bundle ADR 0042); the
                    // dispatcher is optional (the lifecycle-hook seam is off when
                    // symfony/event-dispatcher is absent).
                    '$serverName' => $name,
                    '$dispatcher' => service('event_dispatcher')->nullOnInvalid(),
                    // The JSON:API profiles this server recognizes (bundle ADR 0117),
                    // data-driven from json_api.profiles (default: the three built-ins in
                    // canonical order). The same list feeds the OpenAPI jsonapi.profile
                    // enum + the profile-gated parameters (core ADR 0131).
                    '$profiles' => $profiles,
                ]);

            $factoryRefs[$name] = service($id);
        }

        $services->set(ServerProvider::class)
            ->args(['$factories' => service_locator($factoryRefs)]);

        // The fail-loud eager-load warmer (bundle ADR 0085): at cache:warmup it walks every
        // server's registered types and runs core's EagerLoadValidator over each, so a
        // malformed `on()` declaration (an unknown segment, or a to-many segment at any depth)
        // throws a developer-facing \LogicException at cache:clear / deploy rather than as a
        // runtime 500. Unlike the OpenAPI warmer it is NOT optional — the throw must abort the
        // build.
        $services->set(\haddowg\JsonApiBundle\Serializer\EagerLoadWarmer::class)
            ->args([
                '$servers' => service(ServerProvider::class),
                '$descriptors' => service(\haddowg\JsonApiBundle\Server\RouteDescriptorRegistry::class),
                '$serverNames' => \array_keys($servers),
            ])
            ->tag('kernel.cache_warmer');

        // The symmetric build-time guard: every routed type must be SERVABLE — a read
        // operation needs a DataProvider, a write operation a DataPersister, and an
        // AbstractResource exactly one Id field — else the misconfiguration would only
        // surface as a runtime 500 (or a silent `id: ""`). Also NOT optional.
        $services->set(\haddowg\JsonApiBundle\Server\ServableResourceWarmer::class)
            ->args([
                '$servers' => service(ServerProvider::class),
                '$descriptors' => service(\haddowg\JsonApiBundle\Server\RouteDescriptorRegistry::class),
                '$providers' => service(\haddowg\JsonApiBundle\DataProvider\DataProviderRegistry::class),
                '$persisters' => service(\haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry::class),
                '$typeMetadata' => service(\haddowg\JsonApiBundle\Server\TypeMetadataResolver::class),
                '$serverNames' => \array_keys($servers),
            ])
            ->tag('kernel.cache_warmer');
    }

    /**
     * The container service id for a server's {@see ServerFactory}.
     */
    public static function serverFactoryId(string $server): string
    {
        return 'haddowg.json_api.server_factory.' . $server;
    }

    /**
     * The container service id an application registers a custom server-default
     * {@see \haddowg\JsonApi\Pagination\PaginatorInterface} under: the per-server
     * id `haddowg.json_api.default_paginator.<name>` (resolved first) when `$server`
     * is given, or the generic `haddowg.json_api.default_paginator` (the fallback
     * for all servers) when it is null. Both are optional — absent either, the
     * server uses the built-in capped {@see \haddowg\JsonApi\Pagination\PagePaginator}.
     */
    public static function defaultPaginatorId(?string $server = null): string
    {
        return 'haddowg.json_api.default_paginator' . ($server !== null ? '.' . $server : '');
    }

    /**
     * The comma-joined operation allow-list for the `operations` tag attribute: the
     * resource's explicit `operations` list, or — when `readOnly` is set — the two
     * fetch operations ({@see Operation::FetchCollection}, {@see Operation::FetchOne}).
     * Returns `null` when neither is declared (so the tag attribute is filtered out and
     * the per-capability default — all five for a resource — applies). The attribute
     * constructor forbids declaring both, so this never has to reconcile a conflict.
     */
    private static function operationsTag(AsJsonApiResource $attribute): ?string
    {
        $operations = $attribute->operations;
        if ($operations === [] && $attribute->readOnly) {
            $operations = [Operation::FetchCollection, Operation::FetchOne];
        }

        return $operations === []
            ? null
            : \implode(',', \array_map(static fn(Operation $op): string => $op->value, $operations));
    }

    /**
     * The JSON-encoded response-header config for the `response_headers` tag
     * attribute (bundle ADR 0054): the resource's `cacheHeaders` map plus its
     * `deprecation`/`sunset`/`sunsetLink`, as a single scalar string the
     * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResponseHeadersPass}
     * decodes. Returns `null` when the resource declares no response headers (so the
     * tag attribute is filtered out), keeping a non-declaring resource's tag clean.
     */
    private static function responseHeadersTag(AsJsonApiResource $attribute): ?string
    {
        $config = [];
        if ($attribute->cacheHeaders !== []) {
            $config['cache'] = $attribute->cacheHeaders;
        }
        if ($attribute->deprecation !== null && $attribute->deprecation !== false) {
            $config['deprecation'] = $attribute->deprecation;
        }
        if ($attribute->sunset !== null) {
            $config['sunset'] = $attribute->sunset;
        }
        if ($attribute->sunsetLink !== null) {
            $config['sunset_link'] = $attribute->sunsetLink;
        }

        return $config === [] ? null : \json_encode($config, \JSON_THROW_ON_ERROR);
    }

    /**
     * Normalises the OpenAPI per-operation description overrides
     * (`#[AsJsonApiResource(operationDescriptions:)]`, bundle ADR 0092) into a single
     * JSON-encoded scalar keyed by the {@see Operation} case-name string (a nested map
     * does not survive as a flat tag attribute, like `response_headers`); `null` when
     * none are declared. The attribute constructor has already validated every key
     * (each a valid Operation case name), so the map is encoded as-is.
     */
    private static function operationDescriptionsTag(AsJsonApiResource $attribute): ?string
    {
        if ($attribute->operationDescriptions === []) {
            return null;
        }

        return \json_encode($attribute->operationDescriptions, \JSON_THROW_ON_ERROR);
    }

    /**
     * The per-operation OpenAPI response declarations reduced to a single scalar JSON
     * tag attribute: a map of {@see Operation::value} => list of `{status, jobType?}`,
     * omitting operations with no declared override. `null` when none are declared, so
     * `array_filter` drops the attribute. The {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
     * decodes it back into the route descriptor.
     */
    private static function responsesTag(AsJsonApiResource $attribute): ?string
    {
        $map = [];
        foreach ([
            Operation::Create->value => $attribute->create,
            Operation::Update->value => $attribute->update,
            Operation::Delete->value => $attribute->delete,
            Operation::FetchOne->value => $attribute->fetchOne,
            Operation::FetchCollection->value => $attribute->fetchCollection,
        ] as $operation => $responses) {
            if ($responses === []) {
                continue;
            }

            $map[$operation] = \array_map(
                static fn(OperationResponseInterface $response): array => $response->jobType() !== null
                    ? ['status' => $response->status(), 'jobType' => $response->jobType()]
                    : ['status' => $response->status()],
                $responses,
            );
        }

        return $map === [] ? null : \json_encode($map, \JSON_THROW_ON_ERROR);
    }

    /**
     * Encodes an action's `responds` set to the JSON scalar the ACTION_TAG carries (a
     * list of `{kind, type?, jobType?}`) so it survives the compiled container; `null`
     * when the action declared none (the {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
     * then defaults it to a `200` resource document of the mount type).
     */
    private static function actionRespondsTag(AsJsonApiAction $attribute): ?string
    {
        if ($attribute->responds === []) {
            return null;
        }

        $out = [];
        foreach ($attribute->responds as $response) {
            $out[] = match (true) {
                $response instanceof ActionResource => ['kind' => 'resource', 'type' => $response->bodyType()],
                $response instanceof Accepted => ['kind' => 'accepted', 'jobType' => $response->jobType()],
                $response instanceof MetaResult => ['kind' => 'meta'],
                $response instanceof NoContent => ['kind' => 'nocontent'],
                $response instanceof SeeOther => ['kind' => 'seeother'],
                default => throw new \LogicException(\sprintf(
                    'Unsupported action response %s on action "%s"; declare responds with ActionResource / MetaResult / NoContent / Accepted / SeeOther.',
                    $response::class,
                    $attribute->path,
                )),
            };
        }

        return \json_encode($out, \JSON_THROW_ON_ERROR);
    }

    /**
     * Normalises the OpenAPI `tags` ref list to a comma-joined string (mirroring how
     * `operations`/`server` are joined) so it survives the container as a plain
     * scalar; an empty list (the humanized-type default) is filtered out. Individual
     * tag names are trimmed; a tag name may not contain a comma (it is the list
     * delimiter), so a comma in a name is silently stripped at the split boundary.
     *
     * @param list<string> $tags
     */
    private static function tagsTag(array $tags): ?string
    {
        $names = [];
        foreach ($tags as $tag) {
            $tag = \trim($tag);
            if ($tag !== '' && !\in_array($tag, $names, true)) {
                $names[] = $tag;
            }
        }

        return $names === [] ? null : \implode(',', $names);
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

    /**
     * The resolved `json_api.pagination.max_per_page` — the page-size cap the
     * server's default paginator clamps `page[size]`/`page[limit]` to (`0`
     * disables it). Defaults to core's
     * {@see \haddowg\JsonApi\Pagination\PagePaginator::DEFAULT_MAX_PER_PAGE} when
     * the key is absent.
     *
     * @param array<string, mixed> $config
     */
    private function maxPerPageConfig(array $config): int
    {
        $pagination = $config['pagination'] ?? [];
        $value = \is_array($pagination) ? ($pagination['max_per_page'] ?? null) : null;

        return \is_int($value)
            ? \max(0, $value)
            : \haddowg\JsonApi\Pagination\PagePaginator::DEFAULT_MAX_PER_PAGE;
    }

    /**
     * The resolved `json_api.max_include_depth` — the server default cap on
     * `?include` nesting depth (relationship hops from the primary resource);
     * `0` disables it (unlimited). Defaults to {@see self::DEFAULT_MAX_INCLUDE_DEPTH}
     * (3) when the key is absent. A resource's own `maxIncludeDepth()` override
     * still wins per type.
     *
     * @param array<string, mixed> $config
     */
    private function maxIncludeDepthConfig(array $config): int
    {
        $value = $config['max_include_depth'] ?? null;

        return \is_int($value) ? \max(0, $value) : self::DEFAULT_MAX_INCLUDE_DEPTH;
    }

    /**
     * The resolved `json_api.strict_query_parameters` — whether an unrecognized
     * top-level query-parameter family is rejected with a `400` (bundle ADR 0055,
     * core ADR 0059). Defaults to `true` (strict) when the key is absent; `false`
     * restores the old silent-ignore behaviour.
     *
     * @param array<string, mixed> $config
     */
    private function strictQueryParametersConfig(array $config): bool
    {
        $value = $config['strict_query_parameters'] ?? null;

        return \is_bool($value) ? $value : true;
    }

    /**
     * The resolved `json_api.profiles` list (bundle ADR 0117): the ProfileInterface
     * class-strings every {@see ServerFactory} registers, in order. Each entry must name
     * an existing {@see ProfileInterface} implementation — a misconfiguration fails the
     * build with a clear message rather than a runtime error. Defaults to the three
     * built-ins ({@see ServerFactory::DEFAULT_PROFILES}) when the key is absent.
     *
     * @param array<string, mixed> $config
     *
     * @return list<class-string<ProfileInterface>>
     */
    private function profilesConfig(array $config): array
    {
        $profiles = $config['profiles'] ?? ServerFactory::DEFAULT_PROFILES;
        if (!\is_array($profiles)) {
            return ServerFactory::DEFAULT_PROFILES;
        }

        $resolved = [];
        foreach ($profiles as $class) {
            if (!\is_string($class) || !\is_a($class, ProfileInterface::class, true)) {
                throw new \LogicException(\sprintf(
                    'Each json_api.profiles entry must be a %s class-string; got %s.',
                    ProfileInterface::class,
                    \is_string($class) ? \sprintf('"%s"', $class) : \get_debug_type($class),
                ));
            }

            $resolved[] = $class;
        }

        return $resolved;
    }

    /**
     * The resolved `json_api.doctrine.window_functions` — whether the reference
     * Doctrine provider runs the bounded ROW_NUMBER/COUNT OVER native batch for a
     * windowed include (true) or the per-parent bounded fallback (false). Defaults
     * to `true` when the key is absent; set `false` on a database without window
     * functions (older MySQL/MariaDB/SQLite).
     *
     * @param array<string, mixed> $config
     */
    private function windowFunctionsConfig(array $config): bool
    {
        $doctrine = $config['doctrine'] ?? [];
        $value = \is_array($doctrine) ? ($doctrine['window_functions'] ?? null) : null;

        return \is_bool($value) ? $value : true;
    }

    /**
     * The resolved `json_api.exceptions` map (bundle ADR 0073): each exception
     * class-string keyed to its HTTP status, read by the config-driven
     * {@see ConfiguredExceptionMapper}. Absent / malformed entries are filtered out,
     * so the parameter is always a clean `array<class-string, int>` (empty by
     * default).
     *
     * @param array<string, mixed> $config
     *
     * @return array<class-string, int>
     */
    private function exceptionsConfig(array $config): array
    {
        $exceptions = $config['exceptions'] ?? [];
        if (!\is_array($exceptions)) {
            return [];
        }

        $map = [];
        foreach ($exceptions as $class => $status) {
            if (\is_string($class) && $class !== '' && \is_int($status)) {
                /** @var class-string $class */
                $map[$class] = $status;
            }
        }

        return $map;
    }

    /**
     * The resolved `json_api.atomic_operations` config: a tuple of `enabled` (bool,
     * default false) and `path` (string, default `/operations`). The route loader
     * reads these to emit one `POST {path}` route per server when enabled.
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: bool, 1: string}
     */
    private function atomicOperationsConfig(array $config): array
    {
        $atomic = $config['atomic_operations'] ?? [];
        $atomic = \is_array($atomic) ? $atomic : [];

        $enabled = ($atomic['enabled'] ?? false) === true;

        $path = $atomic['path'] ?? '/operations';
        $path = \is_string($path) && $path !== '' ? $path : '/operations';

        return [$enabled, $path];
    }

    /**
     * The resolved global response-header defaults from `json_api.defaults` (bundle
     * ADR 0054): a tuple of the scalar `cache_headers` map and the
     * `deprecation`/`sunset`/`sunset_link` map, each in the shape the
     * {@see \haddowg\JsonApiBundle\Http\CacheHeaders}/{@see \haddowg\JsonApiBundle\Http\DeprecationHeaders}
     * `fromArray()` factories read. Absent keys yield empty maps (no global default).
     *
     * @param array<string, mixed> $config
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function responseHeaderDefaults(array $config): array
    {
        $defaults = $config['defaults'] ?? [];
        $defaults = \is_array($defaults) ? $defaults : [];

        $cache = $defaults['cache_headers'] ?? [];
        $cache = \is_array($cache) ? \array_filter($cache, static fn(mixed $value): bool => $value !== null) : [];

        $deprecation = [];
        foreach (['deprecation', 'sunset', 'sunset_link'] as $key) {
            if (($defaults[$key] ?? null) !== null) {
                $deprecation[$key] = $defaults[$key];
            }
        }

        return [$cache, $deprecation];
    }
}
