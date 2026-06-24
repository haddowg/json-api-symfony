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
 * It resolves a type to its resource through the global {@see ResourceLocator} — the
 * registration-ordered union of every server's resource class-strings — by matching
 * the {@see AbstractResource}'s static `$type` (read without instantiating, exactly
 * how core's `ResourceRegistry` resolves a type) and then asking the locator for that
 * one instance. A type with no resource, no {@see Id} field, or no encoder yields
 * `null` — i.e. wire == storage, today's behaviour. Resolutions are memoised.
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

    public function __construct(private readonly ResourceLocator $resources) {}

    /**
     * The id encoder declared by `$type`'s resource, or `null` when the type has no
     * resource / no {@see Id} field / no encoder (wire == storage).
     */
    public function encoderFor(string $type): ?IdEncoderInterface
    {
        if (!\array_key_exists($type, $this->encoderCache)) {
            $this->encoderCache[$type] = $this->idFieldFor($type)?->encoder();
        }

        return $this->encoderCache[$type];
    }

    /**
     * The route `{id}` requirement declared by `$type`'s resource id field
     * ({@see Id::matchAs()} / the format shortcuts), or `null` when unconstrained.
     */
    public function routePatternFor(string $type): ?string
    {
        if (!\array_key_exists($type, $this->routePatternCache)) {
            $this->routePatternCache[$type] = $this->idFieldFor($type)?->routePattern();
        }

        return $this->routePatternCache[$type];
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
    public function formatConstraintsFor(string $type): array
    {
        if (!\array_key_exists($type, $this->constraintsCache)) {
            $this->constraintsCache[$type] = $this->idFieldFor($type)?->constraints() ?? [];
        }

        return $this->constraintsCache[$type];
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
    public function allowsClientIdFor(string $type): bool
    {
        if (!\array_key_exists($type, $this->allowsClientIdCache)) {
            $this->allowsClientIdCache[$type] = $this->idFieldFor($type)?->allowsClientId() ?? false;
        }

        return $this->allowsClientIdCache[$type];
    }

    /**
     * Whether `$type`'s resource id field REQUIRES a client-supplied `data.id`
     * ({@see Id::requiresClientId()} — `requireClientId()`). A type with no resource /
     * no id field defaults to `false`. The OpenAPI metadata reads this so the create
     * request schema marks `id` as required (a create without it is core's `403`).
     */
    public function requiresClientIdFor(string $type): bool
    {
        if (!\array_key_exists($type, $this->requiresClientIdCache)) {
            $this->requiresClientIdCache[$type] = $this->idFieldFor($type)?->requiresClientId() ?? false;
        }

        return $this->requiresClientIdCache[$type];
    }

    /**
     * The {@see Id} field of `$type`'s resource, or `null` when the type has no
     * resource or no id field.
     */
    private function idFieldFor(string $type): ?Id
    {
        $resource = $this->resourceFor($type);
        if ($resource === null) {
            return null;
        }

        foreach ($resource->fields() as $field) {
            if ($field instanceof Id) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The resource registered for `$type`: the discovered resource class whose
     * static `$type` matches (read without instantiating), resolved to its service
     * instance through the locator. `null` when no resource declares the type (e.g.
     * a bare serializer/hydrator pair).
     */
    private function resourceFor(string $type): ?AbstractResource
    {
        foreach ($this->resources->classes() as $class) {
            if ($class::$type !== $type) {
                continue;
            }

            $instance = $this->resources->get($class);

            return $instance instanceof AbstractResource ? $instance : null;
        }

        return null;
    }
}
