<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\Http\ResponseHeadersRegistry;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects the declarative response-header config a resource declares via
 * `#[AsJsonApiResource(cacheHeaders: …, deprecation: …, sunset: …)]` (bundle ADR
 * 0054) — the extension JSON-encodes it into the `response_headers` tag attribute
 * on the {@see JsonApiBundle::RESOURCE_TAG} — and assembles a type-keyed scalar
 * `type → {cache, cache_operations, deprecation}` map, injected into the
 * {@see ResponseHeadersRegistry} the {@see \haddowg\JsonApiBundle\EventListener\ResponseHeadersListener}
 * queries.
 *
 * The map flows as plain scalars (the container cannot dump value objects as a
 * service argument); the registry rebuilds the {@see \haddowg\JsonApiBundle\Http\CacheHeaders}
 * / {@see \haddowg\JsonApiBundle\Http\DeprecationHeaders} objects from it, layering
 * each type over the global `json_api.defaults` defaults the extension already wired
 * as the registry's other two arguments. Response headers are **type-keyed and
 * server-independent**, so the `server` tag attribute is irrelevant here. The pass
 * runs unconditionally (the registry is always registered), so the listener always
 * has it.
 *
 * A resource bears two RESOURCE_TAG tags (AbstractResource autoconfiguration + the
 * attribute callback); the `response_headers` JSON lives only on the attribute tag,
 * so the type and the config are resolved across all of a resource's tags — the
 * empty autoconfig tag must not erase the attribute's value.
 */
final class ResponseHeadersPass implements CompilerPassInterface
{
    /**
     * The keys inside a `cacheHeaders` map that are top-level `Cache-Control`
     * directives (everything but the nested `operations` per-read-shape overrides).
     */
    private const array CACHE_DIRECTIVE_KEYS = [
        'max_age',
        's_maxage',
        'public',
        'private',
        'no_cache',
        'must_revalidate',
        'vary',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ResponseHeadersRegistry::class)) {
            return;
        }

        /** @var array<string, array{cache?: array<string, mixed>, cache_operations?: array<string, array<string, mixed>>, deprecation?: array<string, mixed>}> $byType */
        $byType = [];

        foreach ($container->findTaggedServiceIds(JsonApiBundle::RESOURCE_TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);
            if ($class === null) {
                continue;
            }

            $type = $this->typeFromTags($tags, $class);
            if ($type === '') {
                continue;
            }

            $config = $this->configFromTags($tags);
            if ($config !== []) {
                $byType[$type] = $config;
            }
        }

        $container->getDefinition(ResponseHeadersRegistry::class)->setArgument('$byType', $byType);
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
     * The decoded response-header config across a resource's tags: the first tag
     * carrying the `response_headers` JSON string, split into the registry's
     * `{cache, cache_operations, deprecation}` scalar shape (the nested `operations`
     * sub-map of `cacheHeaders` becomes `cache_operations`).
     *
     * @param array<array<string, mixed>> $tags
     *
     * @return array{cache?: array<string, mixed>, cache_operations?: array<string, array<string, mixed>>, deprecation?: array<string, mixed>}
     */
    private function configFromTags(array $tags): array
    {
        $raw = null;
        foreach ($tags as $tag) {
            $value = $tag['response_headers'] ?? null;
            if (\is_string($value) && $value !== '') {
                $raw = $value;
                break;
            }
        }

        if ($raw === null) {
            return [];
        }

        $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [];
        }

        $config = [];

        $cache = $decoded['cache'] ?? null;
        if (\is_array($cache)) {
            $directives = \array_intersect_key($cache, \array_flip(self::CACHE_DIRECTIVE_KEYS));
            if ($directives !== []) {
                $config['cache'] = $directives;
            }

            $operations = $cache['operations'] ?? null;
            if (\is_array($operations)) {
                $byOperation = [];
                foreach ($operations as $operation => $override) {
                    if (\is_string($operation) && \is_array($override)) {
                        $byOperation[$operation] = \array_intersect_key($override, \array_flip(self::CACHE_DIRECTIVE_KEYS));
                    }
                }
                if ($byOperation !== []) {
                    $config['cache_operations'] = $byOperation;
                }
            }
        }

        $deprecation = [];
        if (\array_key_exists('deprecation', $decoded)) {
            $deprecation['deprecation'] = $decoded['deprecation'];
        }
        if (\array_key_exists('sunset', $decoded)) {
            $deprecation['sunset'] = $decoded['sunset'];
        }
        if (\array_key_exists('sunset_link', $decoded)) {
            $deprecation['sunset_link'] = $decoded['sunset_link'];
        }
        if ($deprecation !== []) {
            $config['deprecation'] = $deprecation;
        }

        return $config;
    }
}
