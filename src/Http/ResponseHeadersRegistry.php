<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Http;

use haddowg\JsonApiBundle\Server\ServerProvider;

/**
 * The registry of declarative response-header config (bundle ADR 0054), keyed by
 * server and type (bundle ADR 0144):
 * each JSON:API type's {@see CacheHeaders} (resource-level + per-operation
 * overrides) and {@see DeprecationHeaders}, layered over the global defaults from
 * `json_api.defaults.cache_headers` / `json_api.defaults.deprecation` / `…sunset`.
 *
 * Built from a plain scalar `type → {cache, cache_operations, deprecation}` map the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResponseHeadersPass}
 * assembles from each resource's `#[AsJsonApiResource(cacheHeaders: …)]` tag
 * attributes (the map flows through the container as scalars; value objects are not
 * dumpable). The global defaults arrive as the same scalar shape from the bundle
 * extension.
 *
 * Each server holds only what its own resource class declared, so a type served by
 * different classes on two servers emits each class's headers on its own server, and a
 * server whose class declared nothing gets the global defaults.
 *
 * Resolution layers the resource-level value over the global default, then a
 * per-operation cache override over that — so a type tunes only what it declares.
 * The {@see \haddowg\JsonApiBundle\EventListener\ResponseHeadersListener} queries it
 * per request.
 */
final class ResponseHeadersRegistry
{
    private readonly CacheHeaders $defaultCache;

    private readonly DeprecationHeaders $defaultDeprecation;

    /** @var array<string, array<string, CacheHeaders>> */
    private array $cacheByType = [];

    /** @var array<string, array<string, array<string, CacheHeaders>>> */
    private array $cacheByTypeOperation = [];

    /** @var array<string, array<string, DeprecationHeaders>> */
    private array $deprecationByType = [];

    /**
     * @param array<string, array{
     *     cache?: array<string, mixed>,
     *     cache_operations?: array<string, array<string, mixed>>,
     *     deprecation?: array<string, mixed>,
     * }> $byType the default server's per-type scalar config the compiler pass assembled
     * @param array<string, mixed> $defaultCache the global `cache_headers` default
     * @param array<string, mixed> $defaultDeprecation the global `deprecation`/`sunset` default
     * @param array<string, array<string, array{
     *     cache?: array<string, mixed>,
     *     cache_operations?: array<string, array<string, mixed>>,
     *     deprecation?: array<string, mixed>,
     * }>> $servers every other server's, keyed by server name then type
     */
    public function __construct(array $byType = [], array $defaultCache = [], array $defaultDeprecation = [], array $servers = [])
    {
        /** @var array{max_age?: int|null, s_maxage?: int|null, public?: bool|null, private?: bool|null, no_cache?: bool|null, must_revalidate?: bool|null, vary?: list<string>|null} $defaultCache */
        $this->defaultCache = CacheHeaders::fromArray($defaultCache);
        /** @var array{deprecation?: bool|string|null, sunset?: string|null, sunset_link?: string|null} $defaultDeprecation */
        $this->defaultDeprecation = DeprecationHeaders::fromArray($defaultDeprecation);

        $servers[ServerProvider::DEFAULT_SERVER] = ($servers[ServerProvider::DEFAULT_SERVER] ?? []) + $byType;

        foreach ($servers as $server => $types) {
            foreach ($types as $type => $config) {
                if (isset($config['cache'])) {
                    /** @var array{max_age?: int|null, s_maxage?: int|null, public?: bool|null, private?: bool|null, no_cache?: bool|null, must_revalidate?: bool|null, vary?: list<string>|null} $cache */
                    $cache = $config['cache'];
                    $this->cacheByType[$server][$type] = CacheHeaders::fromArray($cache);
                }

                foreach ($config['cache_operations'] ?? [] as $operation => $override) {
                    /** @var array{max_age?: int|null, s_maxage?: int|null, public?: bool|null, private?: bool|null, no_cache?: bool|null, must_revalidate?: bool|null, vary?: list<string>|null} $override */
                    $this->cacheByTypeOperation[$server][$type][$operation] = CacheHeaders::fromArray($override);
                }

                if (isset($config['deprecation'])) {
                    /** @var array{deprecation?: bool|string|null, sunset?: string|null, sunset_link?: string|null} $deprecation */
                    $deprecation = $config['deprecation'];
                    $this->deprecationByType[$server][$type] = DeprecationHeaders::fromArray($deprecation);
                }
            }
        }
    }

    /**
     * The resolved {@see CacheHeaders} for `$type` on `$server` and the `$operation` read shape:
     * the per-operation override (when declared) layered over the resource-level
     * value layered over the global default. Returns `null` only when the result is
     * empty (no caching anywhere) so the listener can skip the type entirely.
     */
    public function cacheFor(string $type, ResponseHeaderOperation $operation, string $server = ServerProvider::DEFAULT_SERVER): ?CacheHeaders
    {
        $resolved = ($this->cacheByType[$server][$type] ?? new CacheHeaders())->mergeOver($this->defaultCache);

        $override = $this->cacheByTypeOperation[$server][$type][$operation->value] ?? null;
        if ($override !== null) {
            $resolved = $override->mergeOver($resolved);
        }

        return $resolved->isEmpty() ? null : $resolved;
    }

    /**
     * The resolved {@see DeprecationHeaders} for `$type` on `$server`: the resource-level value
     * layered over the global default. Returns `null` when the result is empty (no
     * deprecation/sunset anywhere).
     */
    public function deprecationFor(string $type, string $server = ServerProvider::DEFAULT_SERVER): ?DeprecationHeaders
    {
        $resolved = ($this->deprecationByType[$server][$type] ?? new DeprecationHeaders())->mergeOver($this->defaultDeprecation);

        return $resolved->isEmpty() ? null : $resolved;
    }
}
