<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\OpenApi\Metadata\OperationExampleCodec;
use haddowg\JsonApiBundle\OpenApi\Metadata\OperationScopeOverrides;
use haddowg\JsonApiBundle\OpenApi\Metadata\ResourceDescriptionRegistry;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects the declarative OpenAPI description overrides a resource declares via
 * `#[AsJsonApiResource(description: …, operationDescriptions: …, singular: …)]` (bundle ADR 0092)
 * — the extension records the resource-object description as a scalar `description`
 * tag attribute and the per-operation overrides as one JSON-encoded
 * `operation_descriptions` tag attribute on the {@see JsonApiBundle::RESOURCE_TAG} —
 * and assembles a `type → {description, operations}` scalar map per server injected
 * into the {@see ResourceDescriptionRegistry} the
 * {@see \haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource} reads.
 *
 * Each class is recorded under each of its servers only (bundle ADR 0144): the default
 * server's maps are the `$descriptions` and `$extensions` arguments and every other
 * server's go under `$servers`. The values flow as
 * plain scalars (the container cannot dump value objects); the per-operation map rides
 * as one JSON string because a nested array is not a flat tag attribute. The pass only
 * runs when the registry is defined.
 *
 * The declared OpenAPI vendor extensions (bundle ADR 0127) ride the same way, as one
 * JSON `openapi_extensions` tag attribute, into the registry's `$extensions` argument.
 * A standalone serializer ({@see JsonApiBundle::SERIALIZER_TAG}) can declare them too.
 *
 * The operation example overrides (core ADR 0176) ride as one JSON `operation_examples`
 * tag attribute, from a resource or a standalone serializer, into the registry's
 * `$examples` argument. The OAuth scope overrides (core ADR 0181) ride the same way as
 * `operation_scopes`, under each server's `scopes`.
 */
final class ResourceDescriptionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ResourceDescriptionRegistry::class)) {
            return;
        }

        /** @var array<string, array{descriptions: array<string, array{description?: string, operations?: array<string, string>, singular?: string}>, extensions: array<string, array{type: array<string, mixed>, operations: array<string, array<string, mixed>>}>, examples: array<string, array<string, array{request: mixed, responses: array<int|string, mixed>}|false>>, scopes: array<string, array<string, list<string>>>}> $byServer */
        $byServer = [];

        foreach ($container->findTaggedServiceIds(JsonApiBundle::RESOURCE_TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);
            if ($class === null) {
                continue;
            }

            // A resource bears two RESOURCE_TAG tags (AbstractResource
            // autoconfiguration + the attribute callback), so the type and each value
            // are resolved across all tags — the empty autoconfig tag must not erase
            // the attribute's values.
            $type = $this->typeFromTags($tags, $class);
            if ($type === '') {
                continue;
            }

            $set = [];
            $description = $this->scalarFromTags($tags, 'description');
            if ($description !== null) {
                $set['description'] = $description;
            }

            $singular = $this->scalarFromTags($tags, 'singular');
            if ($singular !== null) {
                $set['singular'] = $singular;
            }

            $operations = $this->operationDescriptionsFromTags($tags);
            if ($operations !== []) {
                $set['operations'] = $operations;
            }

            $declared = $this->extensionsFromTags($tags);
            $examples = OperationExampleCodec::decodeMap($this->scalarFromTags($tags, 'operation_examples'));
            $scopes = OperationScopeOverrides::decodeMap($this->scalarFromTags($tags, 'operation_scopes'));

            // Each class is recorded only under its own servers (bundle ADR 0144).
            foreach ($this->serversFromTags($tags) as $server) {
                $byServer[$server] ??= ['descriptions' => [], 'extensions' => [], 'examples' => [], 'scopes' => []];
                if ($set !== []) {
                    $byServer[$server]['descriptions'][$type] = $set;
                }
                if ($declared !== null) {
                    $byServer[$server]['extensions'][$type] = $declared;
                }
                if ($examples !== []) {
                    $byServer[$server]['examples'][$type] = $examples;
                }
                if ($scopes !== []) {
                    $byServer[$server]['scopes'][$type] = $scopes;
                }
            }
        }

        foreach ($container->findTaggedServiceIds(JsonApiBundle::SERIALIZER_TAG) as $tags) {
            $type = $this->scalarFromTags($tags, 'type');
            $declared = $this->extensionsFromTags($tags);
            $examples = OperationExampleCodec::decodeMap($this->scalarFromTags($tags, 'operation_examples'));
            $scopes = OperationScopeOverrides::decodeMap($this->scalarFromTags($tags, 'operation_scopes'));
            if ($type === null || ($declared === null && $examples === [] && $scopes === [])) {
                continue;
            }

            foreach ($this->serversFromTags($tags) as $server) {
                $byServer[$server] ??= ['descriptions' => [], 'extensions' => [], 'examples' => [], 'scopes' => []];
                if ($declared !== null) {
                    $byServer[$server]['extensions'][$type] ??= $declared;
                }
                if ($examples !== []) {
                    $byServer[$server]['examples'][$type] ??= $examples;
                }
                if ($scopes !== []) {
                    $byServer[$server]['scopes'][$type] ??= $scopes;
                }
            }
        }

        $default = $byServer[ServerProvider::DEFAULT_SERVER] ?? ['descriptions' => [], 'extensions' => [], 'examples' => [], 'scopes' => []];
        unset($byServer[ServerProvider::DEFAULT_SERVER]);

        $container->getDefinition(ResourceDescriptionRegistry::class)
            ->setArgument('$descriptions', $default['descriptions'])
            ->setArgument('$extensions', $default['extensions'])
            ->setArgument('$servers', $byServer)
            ->setArgument('$examples', $default['examples'])
            ->setArgument('$scopes', $default['scopes']);
    }

    /**
     * The servers a resource or serializer is exposed on, read across all its tags the
     * way the ResourceLocatorPass reads them (which also validates the names): the first
     * tag carrying a `server` value, comma-split, else the implicit `default`.
     *
     * @param array<array<string, mixed>> $tags
     *
     * @return list<string>
     */
    private function serversFromTags(array $tags): array
    {
        foreach ($tags as $tag) {
            if (!\array_key_exists('server', $tag)) {
                continue;
            }

            $names = \is_string($tag['server'])
                ? \array_values(\array_unique(\array_filter(\array_map('trim', \explode(',', $tag['server'])), static fn(string $name): bool => $name !== '')))
                : [];

            return $names !== [] ? $names : [ServerProvider::DEFAULT_SERVER];
        }

        return [ServerProvider::DEFAULT_SERVER];
    }

    /**
     * The JSON:API type for a resource across its tags: the first tag's `type`
     * override, else the `AbstractResource`'s static `$type`.
     *
     * @param array<array<string, mixed>> $tags
     */
    private function typeFromTags(array $tags, string $class): string
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
     * The non-empty string recorded under `$key` across a resource's tags, or `null`.
     *
     * @param array<array<string, mixed>> $tags
     */
    private function scalarFromTags(array $tags, string $key): ?string
    {
        foreach ($tags as $tag) {
            $value = $tag[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * The per-operation description overrides decoded from the JSON `operation_descriptions`
     * tag attribute, keyed by {@see \haddowg\JsonApiBundle\Operation\Operation} case
     * name (the extension already validated + encoded them); an empty map when none.
     *
     * @param array<array<string, mixed>> $tags
     *
     * @return array<string, string>
     */
    private function operationDescriptionsFromTags(array $tags): array
    {
        $encoded = $this->scalarFromTags($tags, 'operation_descriptions');
        if ($encoded === null) {
            return [];
        }

        $decoded = \json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $operation => $value) {
            if (\is_string($operation) && \is_string($value) && $value !== '') {
                $map[$operation] = $value;
            }
        }

        return $map;
    }

    /**
     * The vendor extensions decoded from the JSON `openapi_extensions` tag attribute,
     * or `null` when the resource declares none.
     *
     * @param array<array<string, mixed>> $tags
     *
     * @return array{type: array<string, mixed>, operations: array<string, array<string, mixed>>}|null
     */
    private function extensionsFromTags(array $tags): ?array
    {
        $encoded = $this->scalarFromTags($tags, 'openapi_extensions');
        if ($encoded === null) {
            return null;
        }

        $decoded = \json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return null;
        }

        $type = [];
        foreach (\is_array($decoded['type'] ?? null) ? $decoded['type'] : [] as $name => $value) {
            $type[(string) $name] = $value;
        }

        $operations = [];
        foreach (\is_array($decoded['operations'] ?? null) ? $decoded['operations'] : [] as $operation => $set) {
            if (!\is_array($set)) {
                continue;
            }
            foreach ($set as $name => $value) {
                $operations[(string) $operation][(string) $name] = $value;
            }
        }

        return ['type' => $type, 'operations' => $operations];
    }
}
