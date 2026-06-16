<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * The declarative HTTP cache directives a JSON:API type declares for its safe
 * (`GET`) reads via `#[AsJsonApiResource(cacheHeaders: …)]` or the global
 * `json_api.defaults.cache_headers` config key (API-Platform gap G7, bundle ADR
 * 0054). A pure, immutable value object of scalars so it survives the container as
 * a compiled service argument (objects are not dumpable).
 *
 * It maps onto a Symfony {@see Response} via {@see applyTo()}: the RFC-7234
 * `Cache-Control` directives (`max-age`, `s-maxage`, `public`/`private`,
 * `no-cache`, `must-revalidate`) plus the `Vary` header. A {@see CacheHeaders} is
 * applied **only** to a successful `GET` data response — never to a write or an
 * error (the {@see \haddowg\JsonApiBundle\EventListener\ResponseHeadersListener}
 * enforces that).
 *
 * The directives are intentionally all-optional and orthogonal: a `CacheHeaders`
 * with everything `null`/`false` is {@see isEmpty()} and applies nothing (so a
 * type that declares no caching keeps today's no-`Cache-Control` behaviour).
 */
final readonly class CacheHeaders
{
    /**
     * @param int|null          $maxAge         the `max-age` directive (the client/private freshness lifetime, seconds)
     * @param int|null          $sharedMaxAge   the `s-maxage` directive (the shared/CDN freshness lifetime, seconds)
     * @param bool|null         $public         `true` => `public`, `false` => `private`, `null` => leave unset
     * @param bool              $noCache        whether to emit the `no-cache` directive
     * @param bool              $mustRevalidate whether to emit the `must-revalidate` directive
     * @param list<string>      $vary           response header names to add to `Vary`
     */
    public function __construct(
        public ?int $maxAge = null,
        public ?int $sharedMaxAge = null,
        public ?bool $public = null,
        public bool $noCache = false,
        public bool $mustRevalidate = false,
        public array $vary = [],
    ) {}

    /**
     * Rebuilds a {@see CacheHeaders} from the scalar shape the
     * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResponseHeadersPass}
     * flows through the container (and the global `cache_headers` config key uses):
     * a map of `max_age`, `s_maxage`, `public`/`private`, `no_cache`,
     * `must_revalidate`, `vary`.
     *
     * `public: true` and `private: true` are mutually exclusive; `private` wins
     * (it is the more conservative default for an authenticated API).
     *
     * @param array{
     *     max_age?: int|null,
     *     s_maxage?: int|null,
     *     public?: bool|null,
     *     private?: bool|null,
     *     no_cache?: bool|null,
     *     must_revalidate?: bool|null,
     *     vary?: list<string>|null,
     * } $config
     */
    public static function fromArray(array $config): self
    {
        $public = null;
        if (($config['private'] ?? null) === true) {
            $public = false;
        } elseif (($config['public'] ?? null) === true) {
            $public = true;
        } elseif (($config['public'] ?? null) === false) {
            $public = false;
        }

        $vary = $config['vary'] ?? [];

        return new self(
            maxAge: $config['max_age'] ?? null,
            sharedMaxAge: $config['s_maxage'] ?? null,
            public: $public,
            noCache: ($config['no_cache'] ?? false) === true,
            mustRevalidate: ($config['must_revalidate'] ?? false) === true,
            vary: \is_array($vary) ? \array_values(\array_filter($vary, '\is_string')) : [],
        );
    }

    /**
     * A resource-level {@see CacheHeaders} layered over the global default: any
     * directive this object leaves unset inherits the default's value, so a
     * resource overrides only what it declares (and a per-operation override
     * layers over the resource-level one the same way).
     */
    public function mergeOver(self $default): self
    {
        return new self(
            maxAge: $this->maxAge ?? $default->maxAge,
            sharedMaxAge: $this->sharedMaxAge ?? $default->sharedMaxAge,
            public: $this->public ?? $default->public,
            noCache: $this->noCache || $default->noCache,
            mustRevalidate: $this->mustRevalidate || $default->mustRevalidate,
            vary: \array_values(\array_unique([...$default->vary, ...$this->vary])),
        );
    }

    /**
     * Whether this object declares no caching at all — every directive unset/false
     * and no `Vary`. An empty {@see CacheHeaders} applies nothing.
     */
    public function isEmpty(): bool
    {
        return $this->maxAge === null
            && $this->sharedMaxAge === null
            && $this->public === null
            && !$this->noCache
            && !$this->mustRevalidate
            && $this->vary === [];
    }

    /**
     * Writes the declared directives onto `$response`. `Cache-Control` is set
     * through {@see Response::setCache()} (so `max-age`/`s-maxage`/`public`/
     * `private`/`no-cache`/`must-revalidate` compose correctly) and each `Vary`
     * header name is added (`replace: false`, so an app-set `Vary` is appended to,
     * not clobbered).
     */
    public function applyTo(Response $response): void
    {
        $options = [];
        if ($this->maxAge !== null) {
            $options['max_age'] = $this->maxAge;
        }
        if ($this->sharedMaxAge !== null) {
            $options['s_maxage'] = $this->sharedMaxAge;
        }
        if ($this->public === true) {
            $options['public'] = true;
        } elseif ($this->public === false) {
            $options['private'] = true;
        }
        if ($this->noCache) {
            $options['no_cache'] = true;
        }
        if ($this->mustRevalidate) {
            $options['must_revalidate'] = true;
        }

        if ($options !== []) {
            $response->setCache($options);
        }

        foreach ($this->vary as $header) {
            $response->setVary($header, replace: false);
        }
    }
}
