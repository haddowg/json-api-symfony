<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionDeclarations;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Action\ActionDescriptor;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionLinkContributor;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\OpenApi\Metadata\ActionMetadata;
use haddowg\JsonApiBundle\OpenApi\Metadata\OperationExampleCodec;
use haddowg\JsonApiBundle\Operation\Operation;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Server\IdEncoderResolver;
use haddowg\JsonApiBundle\Server\RelationsProviderInterface;
use haddowg\JsonApiBundle\Server\RelationsRegistry;
use haddowg\JsonApiBundle\Server\ResourceLocator;
use haddowg\JsonApiBundle\Server\RouteDescriptorRegistry;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
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
 * relations with **no** resource: the pass collects them into a service locator keyed
 * by server and type and wires the {@see RelationsRegistry} from it (lazy, because
 * relations are runtime objects, not scalars). A resource-less type that declares
 * relations on a server is recorded so its descriptor there gains relationship routes.
 *
 * The pass also builds a per-server, per-type **route descriptor** map (the exposed
 * operation allow-list, the URI segment, whether the type is a full resource, has a
 * hydrator and has relations) and hands it to the {@see JsonApiRouteLoader}, which
 * emits — for the server a routing import names — exactly one route per declared
 * operation (bundle ADR 0025) plus the relationship routes for a type that has
 * relations. Descriptors flow as plain scalar arrays — strings, bools and lists of
 * strings — because Symfony cannot dump arbitrary value objects as a compiled service
 * argument. A write operation (`Create`/`Update`) is compile-time-validated to have a
 * hydrator on each server that exposes it.
 *
 * Everything a resource class, standalone capability or action declares is recorded
 * under the servers that class is assigned to (bundle ADRs 0142 and 0144), so two
 * servers can serve one type name through different classes without either seeing the
 * other's declarations.
 *
 * @phpstan-import-type ActionDescriptorArray from ActionRegistry
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
        /** @var array<string, array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>, responses: array<string, list<array{status: int, jobType?: string, raw?: array<string, mixed>}>>}>> $descriptorsByServer */
        $descriptorsByServer = [];

        // Standalone relations providers, keyed by server and type: the registry
        // resolves the provider lazily, and a resource-less type that declares
        // relations on a server gets relationship routes there.
        $relationReferences = [];
        /** @var array<string, array<string, true>> $standaloneRelationsByServer */
        $standaloneRelationsByServer = [];
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

                $servers = $this->validateServers(
                    $this->parseServers($tag['server'] ?? null),
                    $declaredServers,
                    \sprintf('relations for type "%s"', $type),
                );
                foreach ($servers as $server) {
                    $relationReferences[RelationsRegistry::key($server, $type)] = new Reference($id);
                    $standaloneRelationsByServer[$server][$type] = true;
                }

                // The lookup that names no server: the default server's provider,
                // else the first one declared.
                if (\in_array(ServerProvider::DEFAULT_SERVER, $servers, true) || !isset($relationReferences[$type])) {
                    $relationReferences[$type] = new Reference($id);
                }
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
                // The per-operation OpenAPI response declarations; empty = every op
                // uses its default (the MetadataSource resolves that per operation).
                'responses' => $this->responsesFromTags($tags),
            ];
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

        $standaloneSerializers = $this->collectStandalone(
            $container,
            JsonApiBundle::SERIALIZER_TAG,
            SerializerInterface::class,
            $references,
            $declaredServers,
        );
        $standaloneHydrators = $this->collectStandalone(
            $container,
            JsonApiBundle::HYDRATOR_TAG,
            HydratorInterface::class,
            $references,
            $declaredServers,
        );

        // A standalone hydrator is registered on its own server(s); core needs the
        // serializer/hydrator pair registered together per server, so the hydrator's
        // server membership scopes which server's Server gets it.
        foreach ($standaloneHydrators as $server => $types) {
            foreach ($types as $type => $standalone) {
                $standaloneHydratorsByServer[$server][$type] = $standalone['class'];
            }
        }

        // A standalone (resource-less) type is serialize-only by default: no
        // endpoints unless its serializer's operations allow-list opens them. A
        // server that also serves a resource for the type keeps the resource's
        // descriptor.
        foreach ($standaloneSerializers as $server => $types) {
            foreach ($types as $type => $standalone) {
                $standaloneSerializersByServer[$server][$type] = $standalone['class'];
                $descriptorsByServer[$server][$type] ??= [
                    'uriType' => $type,
                    'isResource' => false,
                    'hasHydrator' => isset($standaloneHydratorsByServer[$server][$type]),
                    'hasRelations' => isset($standaloneRelationsByServer[$server][$type]),
                    'operations' => $standalone['operations'],
                    // The standalone-type OpenAPI tag refs (design §4.7); empty = the
                    // humanized-type default the MetadataSource resolves.
                    'tags' => $standalone['tags'],
                    // A standalone serializer carries no response overrides — its
                    // operations default and use the default response per op.
                    'responses' => [],
                ];
            }
        }

        foreach ($descriptorsByServer as $serverDescriptors) {
            $this->validateWriteCapability($serverDescriptors);
        }

        $locator = ServiceLocatorTagPass::register($container, $references);

        $definition = $container->getDefinition(ResourceLocator::class);
        $definition->setArgument('$services', $locator);
        $definition->setArgument('$classes', \array_values(\array_unique($classes)));

        // The id encoder lookup resolves a type through the classes its server serves.
        if ($container->hasDefinition(IdEncoderResolver::class)) {
            $container->getDefinition(IdEncoderResolver::class)->setArgument(
                '$classesByServer',
                \array_map(static fn(array $classes): array => \array_values(\array_unique($classes)), $resourceClassesByServer),
            );
        }

        // The relations registry is keyed by server and type (relations are runtime
        // objects, not statically readable scalars), so it gets its own locator.
        if ($container->hasDefinition(RelationsRegistry::class)) {
            $relationsLocator = ServiceLocatorTagPass::register($container, $relationReferences);
            $container->getDefinition(RelationsRegistry::class)->setArgument('$providers', $relationsLocator);
        }

        $serverLocators = $this->serverLocators(
            $container,
            $declaredServers,
            $references,
            \array_values(\array_unique($classes)),
            [$resourceClassesByServer, $serializersByServer, $hydratorsByServer, $standaloneSerializersByServer, $standaloneHydratorsByServer],
        );

        // Each declared server's ServerFactory gets only its own buckets (empty when
        // the server exposes nothing).
        foreach ($declaredServers as $server) {
            $factoryId = JsonApiBundle::serverFactoryId($server);
            if (!$container->hasDefinition($factoryId)) {
                continue;
            }

            $factory = $container->getDefinition($factoryId);
            $factory->setArgument('$resources', $serverLocators[$server]);
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
        // resource it mounts on (ADR 0022) on each server.
        $this->collectActions($container, $declaredServers, $descriptorsByServer);

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
     * @param array<string, array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>, responses: array<string, list<array{status: int, jobType?: string, raw?: array<string, mixed>}>>}>> $descriptorsByServer the resource/standalone descriptors per server, read for each mount type's uriType and tags
     */
    private function collectActions(ContainerBuilder $container, array $declaredServers, array $descriptorsByServer): void
    {
        if (!$container->hasDefinition(ActionRegistry::class)) {
            return;
        }

        $handlerReferences = [];
        /** @var array<string, array{type: string, path: string, methods: list<string>, scope: string, input: string, inputType: string, outputType: string, responds: list<array{kind: string, type?: string, jobType?: string, raw?: array<string, mixed>}>, security: ?string, handlerServiceId: string, server: string, tags: string, asLink: bool, summary: ?string, description: ?string, accepts: list<array{mediaType: string, input: string, schema: array<string, mixed>|string|null, binary: bool}>, example: array{request: mixed, responses: array<int|string, mixed>}|false|null, readOnly: ?bool, destructive: ?bool, idempotent: ?bool, extensions: array<string, mixed>}> $actionDescriptors */
        $actionDescriptors = [];
        // The handler class behind each descriptor key, so a declaration error can name it.
        /** @var array<string, string> $actionClasses */
        $actionClasses = [];
        /** @var array<string, list<array{uriType: string, type: string, path: string, methods: list<string>, scope: string, name: string}>> $routeDescriptorsByServer */
        $routeDescriptorsByServer = [];
        // The asLink action link descriptors the ActionLinkContributor renders, keyed by
        // server then by mount type: each is the link key (the action path), the route
        // name the URL generates from, and the action's optional security expression
        // (so the link renders only when the requester may invoke the action).
        /** @var array<string, array<string, list<array{path: string, routeName: string, security: ?string, methods: list<string>}>>> $linksByServerType */
        $linksByServerType = [];

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

                // The declared success-response set (core ADR 0127), decoded from the
                // action's `responds` JSON scalar; empty = the action defaults to a `200`
                // resource document of its mount type. Each entry is a kind discriminator
                // (+ the type an ActionResource names / the jobType an Accepted names). The
                // attribute already validated the real declaration; this is the compiled
                // source of truth, so it re-applies the mount-type default here.
                $responds = $this->parseActionResponds($tag['responds'] ?? null);
                if ($responds === []) {
                    $responds = [['kind' => 'resource', 'type' => $type]];
                }

                // The runtime response serializer the ActionContext renders a DataResponse
                // through: the type an ActionResource names, else the mount type (a meta /
                // 204 / async action renders no resource body, so the mount-type fallback
                // is never exercised by the handler).
                $outputType = $type;
                foreach ($responds as $respondsEntry) {
                    if ($respondsEntry['kind'] === 'resource') {
                        $outputType = $respondsEntry['type'] ?? $type;
                        break;
                    }
                }

                // A handler that narrows its declared return type to exactly a
                // NoContentResponse / MetaResponse has committed to a body shape; require
                // the matching response declaration so the generated document can never
                // drift from the handler's actual output.
                $this->guardActionHandlerOutput($class, $id, $path, $responds);

                $security = \is_string($tag['security'] ?? null) && $tag['security'] !== '' ? $tag['security'] : null;
                $asLink = ($tag['asLink'] ?? false) === true;

                // A resource link needs a resource to hang off — a collection-scope
                // action has none. The attribute constructor already rejects this, but
                // the compiled descriptor map is the source of truth, so re-assert it
                // here so a malformed tag never ships a meaningless link descriptor.
                if ($asLink && $scope === ActionScope::Collection) {
                    throw new \LogicException(\sprintf(
                        'The JSON:API action "%s" on service "%s" declares asLink with a Collection scope; a resource '
                        . 'link requires a resource to hang off, so asLink is supported only for a Resource-scope action.',
                        $path,
                        $id,
                    ));
                }

                $servers = $this->validateServers(
                    $this->parseServers($tag['server'] ?? null),
                    $declaredServers,
                    \sprintf('action "%s" on service "%s"', $path, $id),
                );

                $name = \is_string($tag['name'] ?? null) && $tag['name'] !== '' ? $tag['name'] : null;
                $summary = \is_string($tag['summary'] ?? null) && $tag['summary'] !== '' ? $tag['summary'] : null;
                $description = \is_string($tag['description'] ?? null) && $tag['description'] !== '' ? $tag['description'] : null;
                $accepts = $this->parseActionAccepts($tag['accepts'] ?? null);
                $example = OperationExampleCodec::decodeOne($tag['example'] ?? null);
                $extensions = $this->parseActionExtensions($tag['extensions'] ?? null);

                // The action's OpenAPI tag refs (design §4.7): explicit refs on the
                // action, else inherit the tags of the mount type's resource on each
                // server (bundle ADR 0144) so the action groups with its resource there.
                // Empty when that resource declared none — the MetadataSource then
                // resolves the humanized-type default. Carried through the descriptor as
                // a comma-joined scalar (not a list) so it survives the compiled
                // container, like every other action field.
                $explicitTags = $this->parseTags($tag['tags'] ?? null);

                foreach ($servers as $server) {
                    $actionTags = $explicitTags !== [] ? $explicitTags : ($descriptorsByServer[$server][$type]['tags'] ?? []);
                    // The mount type's URI segment on this server: its descriptor's
                    // uriType when registered there (ADR 0022), else the type itself.
                    $uriType = $descriptorsByServer[$server][$type]['uriType'] ?? $type;

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
                        'responds' => $responds,
                        'security' => $security,
                        'handlerServiceId' => $id,
                        'server' => $server,
                        // The OpenAPI tag refs (design §4.7), comma-joined so the map
                        // value stays a flat scalar shape; the ActionRegistry splits
                        // it back into a list on rehydration.
                        'tags' => \implode(',', $actionTags),
                        // Whether the action is exposed as a `links` member on the mount
                        // type's resources (resource scope only; guarded above).
                        'asLink' => $asLink,
                        'summary' => $summary,
                        'description' => $description,
                        'accepts' => $accepts,
                        'example' => $example,
                        'readOnly' => \is_bool($tag['readOnly'] ?? null) ? $tag['readOnly'] : null,
                        'destructive' => \is_bool($tag['destructive'] ?? null) ? $tag['destructive'] : null,
                        'idempotent' => \is_bool($tag['idempotent'] ?? null) ? $tag['idempotent'] : null,
                        'extensions' => $extensions,
                    ];
                    $actionClasses[$key] = $class;

                    $routeDescriptorsByServer[$server][] = [
                        'uriType' => $uriType,
                        'type' => $type,
                        'path' => $path,
                        'methods' => $methods,
                        'scope' => $scope->name,
                        'name' => $name ?? '',
                    ];

                    // An asLink action contributes a security-aware link on every
                    // rendered resource of its mount type: record the link key (the
                    // action path), the route name the URL generates from (mirroring the
                    // JsonApiRouteLoader's naming exactly), and the security expression.
                    if ($asLink) {
                        $linksByServerType[$server][$type][] = [
                            'path' => $path,
                            'routeName' => $this->actionRouteName($server, $type, $scope, $path, $name),
                            'security' => $security,
                            // The first method names the operation the link's
                            // describedby points at, as the OpenAPI projection does.
                            'methods' => $methods,
                        ];
                    }
                }
            }
        }

        $this->validateActionDeclarations($actionDescriptors, $actionClasses);

        $handlerLocator = ServiceLocatorTagPass::register($container, $handlerReferences);
        $registry = $container->getDefinition(ActionRegistry::class);
        $registry->setArgument('$handlers', $handlerLocator);
        $registry->setArgument('$descriptors', $actionDescriptors);

        if ($container->hasDefinition(JsonApiRouteLoader::class)) {
            $container->getDefinition(JsonApiRouteLoader::class)
                ->setArgument('$actionRouteDescriptorsByServer', $routeDescriptorsByServer);
        }

        // Hand the asLink link descriptors to the ActionLinkContributor (bundle ADR
        // 0091) so it can render each action's URL as a `links` member on its mount
        // type's resources. A no-op when no asLink action is declared (the default map).
        if ($container->hasDefinition(ActionLinkContributor::class)) {
            $container->getDefinition(ActionLinkContributor::class)
                ->setArgument('$linksByServerType', $linksByServerType);
        }
    }

    /**
     * Runs core's {@see ActionDeclarations} check on every type's actions, per server, so
     * a colliding operation id, a reserved action name, a read-only destructive action or
     * a bad extension name fails the container build rather than the first document
     * generation. The actions are checked one at a time in registration order, so the
     * error names the class whose declaration broke the set.
     *
     * @param array<string, ActionDescriptorArray> $descriptors keyed by {@see ActionRegistry::key()}
     * @param array<string, string>                $classes     the handler class behind each key
     */
    private function validateActionDeclarations(array $descriptors, array $classes): void
    {
        /** @var array<string, list<ActionMetadata>> $checked */
        $checked = [];
        foreach ($descriptors as $key => $descriptor) {
            $group = $descriptor['server'] . "\0" . $descriptor['type'];
            $checked[$group][] = new ActionMetadata(ActionRegistry::rehydrate($descriptor));

            try {
                ActionDeclarations::validate($descriptor['type'], $checked[$group]);
            } catch (\LogicException|\InvalidArgumentException $e) {
                throw new \LogicException(\sprintf(
                    'The #[AsJsonApiAction] on %s is invalid on server "%s": %s',
                    $classes[$key] ?? $descriptor['handlerServiceId'],
                    $descriptor['server'],
                    $e->getMessage(),
                ), 0, $e);
            }
        }
    }

    /**
     * The route name the {@see JsonApiRouteLoader} emits for an action, replicated here
     * so the {@see ActionLinkContributor} generates the action's URL from the exact same
     * route: the default server keeps the unprefixed `jsonapi.` namespace, a named
     * server uses `jsonapi.{server}.`; an explicit `name` override wins, else the name
     * is `{prefix}{type}.action.{scope-lowercased}.{path}`.
     */
    private function actionRouteName(string $server, string $type, ActionScope $scope, string $path, ?string $name): string
    {
        $namePrefix = $server === ServerProvider::DEFAULT_SERVER
            ? 'jsonapi.'
            : \sprintf('jsonapi.%s.', $server);

        if ($name !== null && $name !== '') {
            return $namePrefix . $name;
        }

        return \sprintf('%s%s.action.%s.%s', $namePrefix, $type, \strtolower($scope->name), $path);
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
     * Guards a handler's declared output shape against its `responds` set: a handler
     * whose `handle()` return type is narrowed to exactly {@see NoContentResponse} must
     * declare a `204` (`new NoContent()`), and one narrowed to exactly {@see MetaResponse}
     * must declare a meta `200` (`new MetaResult()`) — so the generated OpenAPI response
     * can never drift from the shape the handler actually returns. A handler that keeps
     * the interface's union return type declares no single shape, so it is not
     * constrained here (the `responds` set then governs the projection alone).
     *
     * @param class-string                                              $class
     * @param list<array{kind: string, type?: string, jobType?: string, raw?: array<string, mixed>}> $responds
     */
    private function guardActionHandlerOutput(string $class, string $id, string $path, array $responds): void
    {
        if (!\method_exists($class, 'handle')) {
            return;
        }

        $returnType = (new \ReflectionMethod($class, 'handle'))->getReturnType();
        if (!$returnType instanceof \ReflectionNamedType) {
            return;
        }

        $kinds = \array_map(static fn(array $entry): string => $entry['kind'], $responds);

        $name = $returnType->getName();
        if ($name === NoContentResponse::class && !\in_array('nocontent', $kinds, true)) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on service "%s" has a handler returning NoContentResponse but does not '
                . 'declare a 204 response; a body-less action must declare responds: [new NoContent()] so the '
                . 'generated document advertises a 204.',
                $path,
                $id,
            ));
        }

        if ($name === MetaResponse::class && !\in_array('meta', $kinds, true)) {
            throw new \LogicException(\sprintf(
                'The JSON:API action "%s" on service "%s" has a handler returning MetaResponse but does not declare '
                . 'a meta response; a meta-only action must declare responds: [new MetaResult()] so the generated '
                . 'document advertises a meta document.',
                $path,
                $id,
            ));
        }
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
     * One {@see ResourceLocator} per server. Core's registry injects itself into
     * every resource it resolves, and the server's output timezone, relationship
     * seams and related serializers are all read back through that resolver. A class
     * shared by several servers would be one service whose resolver is whichever
     * server resolved it last, so each of those servers gets its own copy of the
     * service definition (same arguments, so constructor injection still works).
     * A class on a single server keeps its original service.
     *
     * @param list<string>                                    $servers
     * @param array<string, Reference>                        $references every resolvable class-string, mapped to its service
     * @param list<string>                                    $classes    the discovered resource classes
     * @param list<array<string, array<array-key, string>>>   $buckets    per-server maps whose values are the class-strings that server's registry resolves
     *
     * @return array<string, Definition>
     */
    private function serverLocators(ContainerBuilder $container, array $servers, array $references, array $classes, array $buckets): array
    {
        $classesByServer = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $server => $resolved) {
                foreach ($resolved as $class) {
                    $classesByServer[$server][$class] = true;
                }
            }
        }

        $serverCount = [];
        foreach ($classesByServer as $resolved) {
            foreach (\array_keys($resolved) as $class) {
                $serverCount[$class] = ($serverCount[$class] ?? 0) + 1;
            }
        }

        $locators = [];
        foreach ($servers as $server) {
            $serverReferences = $references;
            foreach (\array_keys($classesByServer[$server] ?? []) as $class) {
                if (($serverCount[$class] ?? 0) < 2 || !isset($references[$class])) {
                    continue;
                }

                $original = $container->findDefinition((string) $references[$class]);
                if ($original->isSynthetic()) {
                    continue;
                }

                // The copy is a plain private service: no tags, so no pass collects
                // it a second time, and no decoration of whatever the original decorates.
                $copy = clone $original;
                $copy->clearTags();
                $copy->setDecoratedService(null);
                $copy->setPublic(false);

                $copyId = \sprintf('.haddowg.json_api.server_resource.%s.%s', $server, $class);
                $container->setDefinition($copyId, $copy);
                $serverReferences[$class] = new Reference($copyId);
            }

            $locators[$server] = new Definition(ResourceLocator::class, [
                ServiceLocatorTagPass::register($container, $serverReferences),
                $classes,
            ]);
        }

        return $locators;
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
     * The per-operation response declarations decoded from the first tag carrying a
     * `responses` JSON string (mirroring {@see operationsFromTags} / {@see tagsFromTags}),
     * as a map of {@see Operation} value => list of `{status, jobType?}` for the OpenAPI
     * MetadataSource; empty when none declared (every op then uses its default).
     *
     * @param array<array<string, mixed>> $tags
     *
     * @return array<string, list<array{status: int, jobType?: string, raw?: array<string, mixed>}>>
     */
    private function responsesFromTags(array $tags): array
    {
        foreach ($tags as $tag) {
            if (\array_key_exists('responses', $tag)) {
                return $this->parseResponses($tag['responses']);
            }
        }

        return [];
    }

    /**
     * Parses the JSON `responses` tag string into the descriptor shape, dropping any
     * malformed entry defensively (the attribute already validated the real declaration
     * via {@see \haddowg\JsonApi\OpenApi\Metadata\OperationResponses::validate()}).
     *
     * @return array<string, list<array{status: int, jobType?: string, raw?: array<string, mixed>}>>
     */
    private function parseResponses(mixed $responses): array
    {
        if (!\is_string($responses) || $responses === '') {
            return [];
        }

        $decoded = \json_decode($responses, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $operation => $list) {
            if (!\is_string($operation) || !\is_array($list)) {
                continue;
            }

            $entries = [];
            foreach ($list as $entry) {
                if (!\is_array($entry) || !isset($entry['status']) || !\is_int($entry['status'])) {
                    continue;
                }

                $parsed = ['status' => $entry['status']];
                if (isset($entry['jobType']) && \is_string($entry['jobType'])) {
                    $parsed['jobType'] = $entry['jobType'];
                }
                $entries[] = $parsed;
            }

            if ($entries !== []) {
                $out[$operation] = $entries;
            }
        }

        return $out;
    }

    /**
     * Decodes the JSON `extensions` action-tag string; `[]` when absent. The attribute
     * already validated the names.
     *
     * @return array<string, mixed>
     */
    private function parseActionExtensions(mixed $extensions): array
    {
        if (!\is_string($extensions) || $extensions === '') {
            return [];
        }

        $decoded = \json_decode($extensions, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $name => $value) {
            $out[(string) $name] = $value;
        }

        return $out;
    }

    /**
     * Parses the JSON `accepts` action-tag string into the descriptor shape (see
     * {@see \haddowg\JsonApiBundle\Action\AcceptedMediaTypes}); `[]` when absent.
     *
     * @return list<array{mediaType: string, input: string, schema: array<string, mixed>|string|null, binary: bool}>
     */
    private function parseActionAccepts(mixed $accepts): array
    {
        if (!\is_string($accepts) || $accepts === '') {
            return [];
        }

        $decoded = \json_decode($accepts, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!\is_array($entry) || !\is_string($entry['mediaType'] ?? null) || !\is_string($entry['input'] ?? null)) {
                continue;
            }
            $schema = $entry['schema'] ?? null;
            $entries[] = [
                'mediaType' => $entry['mediaType'],
                'input' => $entry['input'],
                'schema' => \is_string($schema) || \is_array($schema) ? $schema : null,
                'binary' => ($entry['binary'] ?? false) === true,
            ];
        }

        /** @var list<array{mediaType: string, input: string, schema: array<string, mixed>|string|null, binary: bool}> $entries */
        return $entries;
    }

    /**
     * Parses the JSON `responds` action-tag string into the descriptor shape (a list of
     * `{kind, type?, jobType?}`), dropping any malformed entry defensively (the attribute
     * already validated the real declaration via {@see \haddowg\JsonApi\OpenApi\Metadata\ActionResponses::validate()}).
     *
     * @return list<array{kind: string, type?: string, jobType?: string, raw?: array<string, mixed>}>
     */
    private function parseActionResponds(mixed $responds): array
    {
        if (!\is_string($responds) || $responds === '') {
            return [];
        }

        $decoded = \json_decode($responds, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $entry) {
            if (!\is_array($entry) || !isset($entry['kind']) || !\is_string($entry['kind'])) {
                continue;
            }

            $parsed = ['kind' => $entry['kind']];
            if (isset($entry['type']) && \is_string($entry['type'])) {
                $parsed['type'] = $entry['type'];
            }
            if (isset($entry['jobType']) && \is_string($entry['jobType'])) {
                $parsed['jobType'] = $entry['jobType'];
            }
            if (isset($entry['raw']) && \is_array($entry['raw'])) {
                /** @var array<string, mixed> $raw */
                $raw = $entry['raw'];
                $parsed['raw'] = $raw;
            }
            $out[] = $parsed;
        }

        return $out;
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
     * @param array<string, array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>, responses: array<string, list<array{status: int, jobType?: string, raw?: array<string, mixed>}>>}> $descriptors
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
     * `server → type → {class, operations, tags}` map, adding each to `$references` so
     * core's resolver can construct it. Validates the class implements `$contract`.
     * Each service is recorded under every server it is assigned to (parsed + validated
     * against `$declaredServers`), with its parsed operation allow-list (from the tag's
     * operations string, else empty) and OpenAPI tag refs (from the tag's `tags`
     * string, else empty).
     *
     * @param class-string             $contract
     * @param array<string, Reference> $references
     * @param list<string>             $declaredServers
     *
     * @return array<string, array<string, array{class: string, operations: list<string>, tags: list<string>}>>
     */
    private function collectStandalone(ContainerBuilder $container, string $tag, string $contract, array &$references, array $declaredServers): array
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

                $servers = $this->validateServers(
                    $this->parseServers($attributes['server'] ?? null),
                    $declaredServers,
                    \sprintf('type "%s"', $type),
                );

                $entry = [
                    'class' => $class,
                    'operations' => \array_key_exists('operations', $attributes) ? $this->parseOperations($attributes['operations']) : [],
                    'tags' => $this->parseTags($attributes['tags'] ?? null),
                ];

                foreach ($servers as $server) {
                    $map[$server][$type] = $entry;
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
