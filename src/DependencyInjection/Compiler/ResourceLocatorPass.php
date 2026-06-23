<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Action\ActionDescriptor;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Server\RelationsProviderInterface;
use haddowg\JsonApiBundle\Server\RelationsRegistry;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\RouteDescriptorRegistry;
use haddowg\JsonApiBundle\Server\ServerProvider;
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
 * Multi-server (bundle ADR 0034): a resource — or a standalone serializer /
 * hydrator — is assigned to one or more named servers via the tag's `server`
 * value (a comma-joined string the extension wrote from the attribute); absent
 * means the implicit `default`. The pass reads the declared server names from the
 * `haddowg_json_api.servers` parameter, validates every reference, and buckets the
 * resource class-strings, override maps, standalone maps and route descriptors per
 * server. The shared {@see ResourceLocator} stays the **global** resolver (the
 * union of every server's references/classes); only the per-server
 * {@see \haddowg\JsonApiBundle\Server\ServerFactory} args and the route descriptors
 * are scoped. A type assigned to N servers lands in all N buckets.
 *
 * A resource may also declare a custom serializer/hydrator via
 * `#[AsJsonApiResource(serializer: …, hydrator: …)]` (bundle ADR 0023). Those
 * override classes must be registered services too, so each is added to the same
 * locator (keyed by its class-string) — core resolves them through the very same
 * resolver — and a per-server `resourceClass → override` map is handed to that
 * server's ServerFactory, which passes each to core's
 * {@see \haddowg\JsonApi\Server\Server::register()} so the type's reads/writes run
 * through the override.
 *
 * Standalone serializer/hydrator capabilities (`#[AsJsonApiSerializer]` /
 * `#[AsJsonApiHydrator]`, bundle ADR 0024) — a serializer/hydrator registered for
 * a type with **no** resource — flow the same way: each service joins the locator
 * (keyed by its class-string) and a per-server `type → class` map is handed to that
 * server's ServerFactory, which registers them with core's
 * {@see \haddowg\JsonApi\Server\Server::registerSerializerHydrator()}.
 *
 * Standalone relations (`#[AsJsonApiRelations]` on a
 * {@see RelationsProviderInterface} class, bundle ADR 0026) declare a type's
 * relations with **no** resource: the pass collects them into a type-keyed service
 * locator and wires the {@see RelationsRegistry} from it (lazy, because relations
 * are runtime objects, not scalars). A resource-less type that declares relations is
 * recorded so its descriptor gains relationship routes.
 *
 * The pass also builds a per-server, per-type **route descriptor** map (the exposed
 * operation allow-list, the URI segment, whether the type is a full resource, has a
 * hydrator and has relations) and hands it to the {@see JsonApiRouteLoader}, which
 * emits — for the server a routing import names — exactly one route per declared
 * operation (bundle ADR 0025) plus the relationship routes for a type that has
 * relations. Descriptors flow as plain scalar arrays — strings, bools and lists of
 * strings — because Symfony cannot dump arbitrary value objects as a compiled service
 * argument. A write operation (`Create`/`Update`) is compile-time-validated to have a
 * hydrator (server-independent).
 */
final class ResourceLocatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ResourceLocator::class)) {
            return;
        }

        $declaredServers = $this->declaredServers($container);

        $references = [];
        $classes = [];
        /** @var array<string, string> $serializers */
        $serializers = [];
        /** @var array<string, string> $hydrators */
        $hydrators = [];
        /** @var array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>}> $descriptors */
        $descriptors = [];

        // Per-server buckets (ADR 0034): a type/resource assigned to N servers lands
        // in all N. The ResourceLocator stays global (the union below); only the
        // per-server ServerFactory args and the route descriptors are scoped.
        /** @var array<string, list<class-string>> $resourceClassesByServer */
        $resourceClassesByServer = [];
        /** @var array<string, array<string, string>> $serializersByServer */
        $serializersByServer = [];
        /** @var array<string, array<string, string>> $hydratorsByServer */
        $hydratorsByServer = [];
        /** @var array<string, array<string, string>> $standaloneSerializersByServer */
        $standaloneSerializersByServer = [];
        /** @var array<string, array<string, string>> $standaloneHydratorsByServer */
        $standaloneHydratorsByServer = [];
        /** @var array<string, array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>}>> $descriptorsByServer */
        $descriptorsByServer = [];

        // Standalone relations providers, keyed by type: the registry resolves the
        // provider lazily, and a resource-less type that declares relations gets
        // relationship routes. Relations stay global (type-keyed), so the server
        // attribute on #[AsJsonApiRelations] is irrelevant to the registry.
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

            $serializerOverride = null;
            $hydratorOverride = null;
            foreach ($tags as $tag) {
                $serializer = $this->overrideClass($tag, 'serializer', SerializerInterface::class, $id, $container);
                if ($serializer !== null) {
                    $references[$serializer] = new Reference($serializer);
                    $serializers[$class] = $serializer;
                    $serializerOverride = $serializer;
                }

                $hydrator = $this->overrideClass($tag, 'hydrator', HydratorInterface::class, $id, $container);
                if ($hydrator !== null) {
                    $references[$hydrator] = new Reference($hydrator);
                    $hydrators[$class] = $hydrator;
                    $hydratorOverride = $hydrator;
                }
            }

            // A resource carries two RESOURCE_TAG tags when it also bears the
            // attribute (one from AbstractResource autoconfiguration, one from the
            // #[AsJsonApiResource] callback), so the type, server and operations
            // allow-list are resolved across all of them, not per-tag — the empty
            // autoconfig tag must not erase the attribute's value.
            $type = $this->resourceTypeFromTags($tags, $class);
            if ($type === '') {
                // A resource that resolves to no type would otherwise be silently
                // dropped (no routes, no registration). Fail the build instead — but
                // only for an actual AbstractResource; a bare #[AsJsonApiResource] on a
                // non-resource class is handled by the standalone serializer/hydrator
                // paths.
                if (\is_a($class, AbstractResource::class, true)) {
                    throw new \LogicException(\sprintf(
                        'The JSON:API resource "%s" must declare a non-empty static $type.',
                        $class,
                    ));
                }

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

            $descriptor = [
                'uriType' => $uriType,
                'isResource' => true,
                'hasHydrator' => true,
                'hasRelations' => true,
                'operations' => $this->operationsFromTags($tags) ?? self::allOperations(),
                // The OpenAPI tag refs (design §4.7); empty = the humanized-type
                // default the MetadataSource resolves. Read across all tags so the
                // empty autoconfig tag does not erase the attribute's value.
                'tags' => $this->tagsFromTags($tags),
            ];
            $descriptors[$type] = $descriptor;

            $servers = $this->serversFromTags($tags, $declaredServers, \sprintf('resource "%s"', $class));
            foreach ($servers as $server) {
                $resourceClassesByServer[$server][] = $class;
                if ($serializerOverride !== null) {
                    $serializersByServer[$server][$class] = $serializerOverride;
                }
                if ($hydratorOverride !== null) {
                    $hydratorsByServer[$server][$class] = $hydratorOverride;
                }
                $descriptorsByServer[$server][$type] = $descriptor;
            }
        }

        /** @var array<string, list<string>> $standaloneSerializerOperations */
        $standaloneSerializerOperations = [];
        /** @var array<string, list<string>> $standaloneSerializerServers */
        $standaloneSerializerServers = [];
        /** @var array<string, list<string>> $standaloneSerializerTags */
        $standaloneSerializerTags = [];
        $standaloneSerializers = $this->collectStandalone(
            $container,
            JsonApiBundle::SERIALIZER_TAG,
            SerializerInterface::class,
            $references,
            $declaredServers,
            $standaloneSerializerServers,
            $standaloneSerializerOperations,
            $standaloneSerializerTags,
        );
        /** @var array<string, list<string>> $standaloneHydratorServers */
        $standaloneHydratorServers = [];
        $standaloneHydrators = $this->collectStandalone(
            $container,
            JsonApiBundle::HYDRATOR_TAG,
            HydratorInterface::class,
            $references,
            $declaredServers,
            $standaloneHydratorServers,
        );

        // A standalone (resource-less) type is serialize-only by default: no
        // endpoints unless its serializer's operations allow-list opens them.
        foreach ($standaloneSerializers as $type => $class) {
            if (!isset($descriptors[$type])) {
                $descriptors[$type] = [
                    'uriType' => $type,
                    'isResource' => false,
                    'hasHydrator' => isset($standaloneHydrators[$type]),
                    'hasRelations' => isset($typesWithStandaloneRelations[$type]),
                    'operations' => $standaloneSerializerOperations[$type] ?? [],
                    // The standalone-type OpenAPI tag refs (design §4.7); empty = the
                    // humanized-type default the MetadataSource resolves.
                    'tags' => $standaloneSerializerTags[$type] ?? [],
                ];
            }

            foreach ($standaloneSerializerServers[$type] ?? [ServerProvider::DEFAULT_SERVER] as $server) {
                $standaloneSerializersByServer[$server][$type] = $class;
                $descriptorsByServer[$server][$type] = $descriptors[$type];
            }
        }

        // A standalone hydrator is registered on its own server(s); core needs the
        // serializer/hydrator pair registered together per server, so the hydrator's
        // server membership scopes which server's Server gets it.
        foreach ($standaloneHydrators as $type => $class) {
            foreach ($standaloneHydratorServers[$type] ?? [ServerProvider::DEFAULT_SERVER] as $server) {
                $standaloneHydratorsByServer[$server][$type] = $class;
            }
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

        // Each declared server's ServerFactory gets only its own buckets (empty when
        // the server exposes nothing).
        foreach ($declaredServers as $server) {
            $factoryId = JsonApiBundle::serverFactoryId($server);
            if (!$container->hasDefinition($factoryId)) {
                continue;
            }

            $factory = $container->getDefinition($factoryId);
            $factory->setArgument('$resourceClasses', \array_values(\array_unique($resourceClassesByServer[$server] ?? [])));
            $factory->setArgument('$serializersByClass', $serializersByServer[$server] ?? []);
            $factory->setArgument('$hydratorsByClass', $hydratorsByServer[$server] ?? []);
            $factory->setArgument('$standaloneSerializers', $standaloneSerializersByServer[$server] ?? []);
            $factory->setArgument('$standaloneHydrators', $standaloneHydratorsByServer[$server] ?? []);
        }

        // Custom actions (bundle ADR 0076): collect the ACTION_TAG handler services
        // + their flattened attribute metadata into the ActionRegistry's descriptor
        // map (keyed by the composite (server, type, scope, path)) and handler
        // locator, plus the per-server action route descriptors the loader emits. The
        // mount type's uriType resolves from its resource descriptor when present
        // (else the type itself), so an action hangs off the same URI segment as the
        // resource it mounts on (ADR 0022).
        $this->collectActions($container, $declaredServers, $descriptors);

        if ($container->hasDefinition(JsonApiRouteLoader::class)) {
            $container->getDefinition(JsonApiRouteLoader::class)->setArgument('$routeDescriptorsByServer', $descriptorsByServer);
        }

        // Surface the same per-server descriptor map as a service so the OpenAPI
        // MetadataSource can enumerate a server's types + read uriType/operations/
        // tags at runtime, independent of the route loader.
        if ($container->hasDefinition(RouteDescriptorRegistry::class)) {
            $container->getDefinition(RouteDescriptorRegistry::class)->setArgument('$descriptorsByServer', $descriptorsByServer);
        }
    }

    /**
     * Collects every {@see JsonApiBundle::ACTION_TAG} handler service and wires the
     * custom-action seam (bundle ADR 0076): the {@see ActionRegistry}'s
     * composite-keyed {@see ActionDescriptor} map + a handler service-locator (keyed
     * by service id, resolved lazily so a handler with real constructor dependencies
     * is built only when invoked), and the per-server **action route descriptors**
     * the {@see JsonApiRouteLoader} emits — each a plain scalar array (the loader and
     * the registry cannot receive value objects as compiled arguments).
     *
     * The mount type's URI segment resolves from that type's resource descriptor's
     * `uriType` when present (a resource may customize it, ADR 0022), else the type
     * itself. The decoupled-document defaults are applied here:
     * `inputType`/`outputType` fall back to the mount `type` when the attribute left
     * them `null`.
     *
     * @param list<string>                                                                                                                             $declaredServers
     * @param array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>}> $descriptors the resource/standalone descriptors, read for each mount type's uriType
     */
    private function collectActions(ContainerBuilder $container, array $declaredServers, array $descriptors): void
    {
        if (!$container->hasDefinition(ActionRegistry::class)) {
            return;
        }

        $handlerReferences = [];
        /** @var array<string, array{type: string, path: string, methods: list<string>, scope: string, input: string, inputType: string, outputType: string, security: ?string, handlerServiceId: string, server: string, tags: string}> $actionDescriptors */
        $actionDescriptors = [];
        /** @var array<string, list<array{uriType: string, type: string, path: string, methods: list<string>, scope: string, name: string}>> $routeDescriptorsByServer */
        $routeDescriptorsByServer = [];

        foreach ($container->findTaggedServiceIds(JsonApiBundle::ACTION_TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);
            if ($class === null) {
                continue;
            }

            if (!\is_a($class, ActionHandlerInterface::class, true)) {
                throw new \LogicException(\sprintf(
                    'The service "%s" registered with #[AsJsonApiAction] must implement %s.',
                    $id,
                    ActionHandlerInterface::class,
                ));
            }

            $handlerReferences[$id] = new Reference($id);

            foreach ($tags as $tag) {
                $type = $tag['type'] ?? null;
                $path = $tag['path'] ?? null;
                if (!\is_string($type) || $type === '' || !\is_string($path) || $path === '') {
                    continue;
                }

                // The action name is emitted as a literal URL segment under
                // `-actions/`, so it must be a single path segment (no slash) — a
                // multi-segment action name is rejected at build time, not silently
                // mis-routed.
                if (\str_contains($path, '/')) {
                    throw new \LogicException(\sprintf(
                        'The JSON:API action path "%s" on service "%s" must be a single URL segment (no "/").',
                        $path,
                        $id,
                    ));
                }

                $scope = $this->actionScope($tag, $id);
                $input = $this->actionInput($tag, $id);
                $methods = $this->actionMethods($tag);
                $inputType = \is_string($tag['inputType'] ?? null) && $tag['inputType'] !== '' ? $tag['inputType'] : $type;
                $declaredOutputType = \is_string($tag['outputType'] ?? null) && $tag['outputType'] !== '' ? $tag['outputType'] : null;
                $returns204 = ($tag['returns204'] ?? false) === true;

                // A no-output (204) action keeps the empty-string sentinel as its
                // outputType — the ActionMetadata maps it to null so the generated
                // document advertises a 204 instead of a 200 body (design §4.5). It is
                // mutually exclusive with an explicit outputType (a 204 carries no body).
                if ($returns204 && $declaredOutputType !== null) {
                    throw new \LogicException(\sprintf(
                        'The JSON:API action "%s" on service "%s" declares both returns204 and an outputType; a 204 action describes no response body, so they are mutually exclusive.',
                        $path,
                        $id,
                    ));
                }

                $outputType = match (true) {
                    $returns204 => '',
                    $declaredOutputType !== null => $declaredOutputType,
                    default => $type,
                };
                $security = \is_string($tag['security'] ?? null) && $tag['security'] !== '' ? $tag['security'] : null;

                $servers = $this->validateServers(
                    $this->parseServers($tag['server'] ?? null),
                    $declaredServers,
                    \sprintf('action "%s" on service "%s"', $path, $id),
                );

                // The mount type's URI segment: its resource descriptor's uriType
                // when registered (ADR 0022), else the type itself.
                $uriType = $descriptors[$type]['uriType'] ?? $type;
                $name = \is_string($tag['name'] ?? null) && $tag['name'] !== '' ? $tag['name'] : null;

                // The action's OpenAPI tag refs (design §4.7): explicit refs on the
                // action, else inherit the mount type's resource tags so the action
                // groups with its resource. Empty when the mount type declared none —
                // the MetadataSource then resolves the humanized-type default. Carried
                // through the descriptor as a comma-joined scalar (not a list) so it
                // survives the compiled container, like every other action field.
                $actionTags = $this->parseTags($tag['tags'] ?? null);
                if ($actionTags === []) {
                    $actionTags = $descriptors[$type]['tags'] ?? [];
                }

                foreach ($servers as $server) {
                    $key = ActionRegistry::key($server, $type, $scope, $path);
                    if (isset($actionDescriptors[$key])) {
                        throw new \LogicException(\sprintf(
                            'A JSON:API custom action "%s" is already declared for type "%s" (%s scope) on server "%s"; '
                            . 'action names must be unique per (server, type, scope).',
                            $path,
                            $type,
                            $scope->name,
                            $server,
                        ));
                    }

                    // A plain scalar array (not an ActionDescriptor value object): the
                    // container dumper cannot dump a value object — nor the
                    // ActionScope/ActionInput enums it carries — as a compiled
                    // argument, exactly as the route descriptors below stay scalar. The
                    // ActionRegistry rehydrates the value object (enums by name) on
                    // lookup.
                    $actionDescriptors[$key] = [
                        'type' => $type,
                        'path' => $path,
                        'methods' => $methods,
                        'scope' => $scope->name,
                        'input' => $input->name,
                        'inputType' => $inputType,
                        'outputType' => $outputType,
                        'security' => $security,
                        'handlerServiceId' => $id,
                        'server' => $server,
                        // The OpenAPI tag refs (design §4.7), comma-joined so the map
                        // value stays a flat scalar shape; the ActionRegistry splits
                        // it back into a list on rehydration.
                        'tags' => \implode(',', $actionTags),
                    ];

                    $routeDescriptorsByServer[$server][] = [
                        'uriType' => $uriType,
                        'type' => $type,
                        'path' => $path,
                        'methods' => $methods,
                        'scope' => $scope->name,
                        'name' => $name ?? '',
                    ];
                }
            }
        }

        $handlerLocator = ServiceLocatorTagPass::register($container, $handlerReferences);
        $registry = $container->getDefinition(ActionRegistry::class);
        $registry->setArgument('$handlers', $handlerLocator);
        $registry->setArgument('$descriptors', $actionDescriptors);

        if ($container->hasDefinition(JsonApiRouteLoader::class)) {
            $container->getDefinition(JsonApiRouteLoader::class)
                ->setArgument('$actionRouteDescriptorsByServer', $routeDescriptorsByServer);
        }
    }

    /**
     * The {@see ActionScope} the tag's `scope` case name names (default
     * {@see ActionScope::Resource}); an unknown name is a build error.
     *
     * @param array<string, mixed> $tag
     */
    private function actionScope(array $tag, string $id): ActionScope
    {
        $scope = $tag['scope'] ?? null;
        if (!\is_string($scope) || $scope === '') {
            return ActionScope::Resource;
        }

        foreach (ActionScope::cases() as $case) {
            if ($case->name === $scope) {
                return $case;
            }
        }

        throw new \LogicException(\sprintf('Unknown action scope "%s" on service "%s".', $scope, $id));
    }

    /**
     * The {@see ActionInput} the tag's `input` case name names (default
     * {@see ActionInput::None}); an unknown name is a build error.
     *
     * @param array<string, mixed> $tag
     */
    private function actionInput(array $tag, string $id): ActionInput
    {
        $input = $tag['input'] ?? null;
        if (!\is_string($input) || $input === '') {
            return ActionInput::None;
        }

        foreach (ActionInput::cases() as $case) {
            if ($case->name === $input) {
                return $case;
            }
        }

        throw new \LogicException(\sprintf('Unknown action input mode "%s" on service "%s".', $input, $id));
    }

    /**
     * The author-declared HTTP method allow-list parsed from the comma-joined
     * `methods` tag string, uppercased; defaults to `['POST']` when absent/empty.
     *
     * @param array<string, mixed> $tag
     *
     * @return list<string>
     */
    private function actionMethods(array $tag): array
    {
        $methods = $tag['methods'] ?? null;
        if (!\is_string($methods) || $methods === '') {
            return ['POST'];
        }

        $values = [];
        foreach (\explode(',', $methods) as $method) {
            $method = \strtoupper(\trim($method));
            if ($method !== '' && !\in_array($method, $values, true)) {
                $values[] = $method;
            }
        }

        return $values === [] ? ['POST'] : $values;
    }

    /**
     * The declared server names, read from the `haddowg_json_api.servers` container
     * parameter the extension set; falls back to just `default` when absent (e.g. a
     * test that registers the pass without the extension).
     *
     * @return list<string>
     */
    private function declaredServers(ContainerBuilder $container): array
    {
        if (!$container->hasParameter('haddowg_json_api.servers')) {
            return [ServerProvider::DEFAULT_SERVER];
        }

        $servers = $container->getParameter('haddowg_json_api.servers');
        if (!\is_array($servers) || $servers === []) {
            return [ServerProvider::DEFAULT_SERVER];
        }

        $names = [];
        foreach ($servers as $name) {
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names === [] ? [ServerProvider::DEFAULT_SERVER] : $names;
    }

    /**
     * The server names a resource is exposed on, resolved across all its tags (the
     * empty autoconfig tag must not erase the attribute's `server`): the first tag
     * carrying a `server` value wins, parsed via {@see parseServers}; absent → the
     * implicit `default`. Every name is validated to be declared.
     *
     * @param array<array<string, mixed>> $tags
     * @param list<string>                 $declaredServers
     *
     * @return list<string>
     */
    private function serversFromTags(array $tags, array $declaredServers, string $subject): array
    {
        $raw = null;
        foreach ($tags as $tag) {
            if (\array_key_exists('server', $tag)) {
                $raw = $tag['server'];
                break;
            }
        }

        return $this->validateServers($this->parseServers($raw), $declaredServers, $subject);
    }

    /**
     * Parses a tag's `server` value into a deduped list of names: null/empty/absent
     * → `['default']`; a comma-joined string is split; whitespace is trimmed.
     *
     * @return list<string>
     */
    private function parseServers(mixed $raw): array
    {
        if (!\is_string($raw) || $raw === '') {
            return [ServerProvider::DEFAULT_SERVER];
        }

        $names = [];
        foreach (\explode(',', $raw) as $name) {
            $name = \trim($name);
            if ($name !== '' && !\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names === [] ? [ServerProvider::DEFAULT_SERVER] : $names;
    }

    /**
     * Validates every referenced server name is one of the declared servers.
     *
     * @param list<string> $servers
     * @param list<string> $declaredServers
     *
     * @return list<string>
     */
    private function validateServers(array $servers, array $declaredServers, string $subject): array
    {
        foreach ($servers as $server) {
            if (!\in_array($server, $declaredServers, true)) {
                throw new \LogicException(\sprintf(
                    'The JSON:API %s references an unknown server "%s"; declare it under json_api.servers '
                    . '(declared servers: %s).',
                    $subject,
                    $server,
                    \implode(', ', $declaredServers),
                ));
            }
        }

        return $servers;
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
     * The OpenAPI tag refs declared across a resource's tags (design §4.7), resolved
     * like {@see operationsFromTags}: the first tag carrying a `tags` string wins (so
     * the empty autoconfig tag does not erase the attribute's value), parsed via
     * {@see parseTags}; empty when none declared (the MetadataSource then applies the
     * humanized-type default).
     *
     * @param array<array<string, mixed>> $tags
     *
     * @return list<string>
     */
    private function tagsFromTags(array $tags): array
    {
        foreach ($tags as $tag) {
            if (\array_key_exists('tags', $tag)) {
                return $this->parseTags($tag['tags']);
            }
        }

        return [];
    }

    /**
     * Parses the comma-joined OpenAPI tag string into a deduped list of trimmed,
     * non-empty tag names; a non-string / empty value yields an empty list.
     *
     * @return list<string>
     */
    private function parseTags(mixed $tags): array
    {
        if (!\is_string($tags) || $tags === '') {
            return [];
        }

        $names = [];
        foreach (\explode(',', $tags) as $name) {
            $name = \trim($name);
            if ($name !== '' && !\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
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
     * @param array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>}> $descriptors
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
     * can construct it. Validates the class implements `$contract`. Each type's
     * server membership (parsed + validated against `$declaredServers`) is recorded
     * into `$serversByType`; when `$operationsByType` is provided, each type's parsed
     * operation allow-list (from the tag's operations string, else empty) is too;
     * when `$tagsByType` is provided, each type's parsed OpenAPI tag refs (from the
     * tag's `tags` string, else empty) are too.
     *
     * @param class-string                     $contract
     * @param array<string, Reference>         $references
     * @param list<string>                     $declaredServers
     * @param array<string, list<string>>      $serversByType
     * @param array<string, list<string>>|null $operationsByType
     * @param array<string, list<string>>|null $tagsByType
     *
     * @return array<string, string>
     */
    private function collectStandalone(ContainerBuilder $container, string $tag, string $contract, array &$references, array $declaredServers, array &$serversByType, ?array &$operationsByType = null, ?array &$tagsByType = null): array
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

                $serversByType[$type] = $this->validateServers(
                    $this->parseServers($attributes['server'] ?? null),
                    $declaredServers,
                    \sprintf('type "%s"', $type),
                );

                if ($operationsByType !== null) {
                    $operationsByType[$type] = \array_key_exists('operations', $attributes)
                        ? $this->parseOperations($attributes['operations'])
                        : [];
                }

                if ($tagsByType !== null) {
                    $tagsByType[$type] = $this->parseTags($attributes['tags'] ?? null);
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
