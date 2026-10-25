<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * Resolves a JSON:API type's id transform from the resource that declares it: the
 * {@see Id} field's {@see IdEncoderInterface} (storage-key ⇄ wire-id codec) and its
 * route `{id}` requirement ({@see Id::routePattern()}).
 *
 * The encode/decode boundary is split by where the id flows (bundle ADR 0038): core
 * owns the entity's-own-id transform (encode on serialize, decode a client-generated
 * id on create), and the reference Doctrine layer owns the id-as-lookup-key
 * transforms the storage-agnostic {@see \haddowg\JsonApiBundle\DataProvider\DataProviderInterface}
 * / {@see \haddowg\JsonApiBundle\DataPersister\DataPersisterInterface} SPI passes as
 * **wire** strings — the route `{id}` (decode before find/query) and linkage ids
 * (decode before `getReference`). Those SPI signatures stay wire-id; only the
 * Doctrine impl decodes internally, so the in-memory provider (which has no encoder)
 * is unaffected and wire == storage there. This resolver is the shared seam the
 * Doctrine provider, persister and route loader read the encoder/route pattern
 * through.
 *
 * Two servers may serve one type through different resource classes with different
 * {@see Id} fields, so a type resolves to the class its server serves it with (bundle
 * ADR 0145): the server a caller names, else the one the current operation
 * {@see bind() bound}, else the default server. When that server does not serve the
 * type, the first class that declares it anywhere answers. The class is matched by
 * the {@see AbstractResource}'s static `$type` (read without instantiating, exactly
 * how core's `ResourceRegistry` resolves a type) and then fetched from the
 * {@see ResourceLocator}. A type with no resource, no {@see Id} field, or no encoder
 * yields `null` — i.e. wire == storage, today's behaviour. Resolutions are memoised
 * per server and type.
 */
final class IdEncoderResolver
{
    /**
     * @var array<string, IdEncoderInterface|null>
     */
    private array $encoderCache = [];

    /**
     * @var array<string, string|null>
     */
    private array $routePatternCache = [];

    /**
     * @var array<string, list<ConstraintInterface>>
     */
    private array $constraintsCache = [];

    /**
     * @var array<string, bool>
     */
    private array $allowsClientIdCache = [];

    /**
     * @var array<string, bool>
     */
    private array $requiresClientIdCache = [];

    private string $boundServer = ServerProvider::DEFAULT_SERVER;

    /**
     * @param array<string, list<class-string>> $classesByServer each server's resource classes, in registration order
     */
    public function __construct(
        private readonly ResourceLocator $resources,
        private readonly array $classesByServer = [],
    ) {}

    /**
     * Sets the server the current operation runs on, which answers every lookup that
     * names no server. Before any operation binds one, the default server answers.
     */
    public function bind(string $server): void
    {
        $this->boundServer = $server;
    }

    /**
     * The id encoder declared by `$type`'s resource, or `null` when the type has no
     * resource / no {@see Id} field / no encoder (wire == storage).
     */
    public function encoderFor(string $type, ?string $server = null): ?IdEncoderInterface
    {
        $server ??= $this->boundServer;
        $key = $server . "\0" . $type;
        if (!\array_key_exists($key, $this->encoderCache)) {
            $this->encoderCache[$key] = $this->idFieldFor($type, $server)?->encoder();
        }

        return $this->encoderCache[$key];
    }

    /**
     * The route `{id}` requirement declared by `$type`'s resource id field
     * ({@see Id::matchAs()} / the format shortcuts), or `null` when unconstrained.
     */
    public function routePatternFor(string $type, ?string $server = null): ?string
    {
        $server ??= $this->boundServer;
        $key = $server . "\0" . $type;
        if (!\array_key_exists($key, $this->routePatternCache)) {
            $this->routePatternCache[$key] = $this->idFieldFor($type, $server)?->routePattern();
        }

        return $this->routePatternCache[$key];
    }

    /**
     * The id-format constraints declared on `$type`'s resource id field — the
     * {@see ConstraintInterface} value objects the `uuid()` / `ulid()` / `numeric()`
     * / `pattern()` shortcuts append. Core only declares these (it has no constraint
     * executor); the Symfony Validator bridge translates them to validate a write's
     * relationship **linkage** ids against the *related* type's id format, the same
     * vocabulary that governs a client-supplied id of the type itself. Empty when the
     * type has no resource, no id field, or an unconstrained id (wire == any).
     *
     * @return list<ConstraintInterface>
     */
    public function formatConstraintsFor(string $type, ?string $server = null): array
    {
        $server ??= $this->boundServer;
        $key = $server . "\0" . $type;
        if (!\array_key_exists($key, $this->constraintsCache)) {
            $this->constraintsCache[$key] = $this->idFieldFor($type, $server)?->constraints() ?? [];
        }

        return $this->constraintsCache[$key];
    }

    /**
     * Whether `$type`'s resource id field accepts a client-supplied `data.id`
     * ({@see Id::allowsClientId()} — `allowClientId()` or `requireClientId()`). A type
     * with no resource / no id field defaults to `false`, mirroring the id field's own
     * default (client ids forbidden). The validator bridge reads this so it only
     * format-checks a client id the type would actually accept — for a forbidden type
     * any supplied id is core's `403`, irrespective of its format (`Id::idField()` is
     * protected, so the policy is surfaced here).
     */
    public function allowsClientIdFor(string $type, ?string $server = null): bool
    {
        $server ??= $this->boundServer;
        $key = $server . "\0" . $type;
        if (!\array_key_exists($key, $this->allowsClientIdCache)) {
            $this->allowsClientIdCache[$key] = $this->idFieldFor($type, $server)?->allowsClientId() ?? false;
        }

        return $this->allowsClientIdCache[$key];
    }

    /**
     * Whether `$type`'s resource id field REQUIRES a client-supplied `data.id`
     * ({@see Id::requiresClientId()} — `requireClientId()`). A type with no resource /
     * no id field defaults to `false`. The OpenAPI metadata reads this so the create
     * request schema marks `id` as required (a create without it is core's `403`).
     */
    public function requiresClientIdFor(string $type, ?string $server = null): bool
    {
        $server ??= $this->boundServer;
        $key = $server . "\0" . $type;
        if (!\array_key_exists($key, $this->requiresClientIdCache)) {
            $this->requiresClientIdCache[$key] = $this->idFieldFor($type, $server)?->requiresClientId() ?? false;
        }

        return $this->requiresClientIdCache[$key];
    }

    /**
     * The {@see Id} field of `$type`'s resource, or `null` when the type has no
     * resource or no id field.
     */
    private function idFieldFor(string $type, string $server): ?Id
    {
        $resource = $this->resourceFor($type, $server);
        if ($resource === null) {
            return null;
        }

        foreach ($resource->allFields() as $field) {
            if ($field instanceof Id) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The resource `$server` serves `$type` with: the first of that server's classes
     * whose static `$type` matches (read without instantiating), else the first such
     * class on any server, resolved to its service instance through the locator.
     * `null` when no resource declares the type (e.g. a bare serializer/hydrator pair).
     */
    private function resourceFor(string $type, string $server): ?AbstractResource
    {
        foreach ([$this->classesByServer[$server] ?? [], $this->resources->classes()] as $classes) {
            foreach ($classes as $class) {
                if (!\is_a($class, AbstractResource::class, true) || $class::$type !== $type) {
                    continue;
                }

                $instance = $this->resources->get($class);

                return $instance instanceof AbstractResource ? $instance : null;
            }
        }

        return null;
    }
}
