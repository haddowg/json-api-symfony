<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

use haddowg\JsonApiBundle\Operation\Operation;

/**
 * Optional metadata for a JSON:API Resource service. Discovery is zero-config by
 * default (any {@see \haddowg\JsonApi\Resource\AbstractResource} is auto-registered);
 * this attribute is only needed to carry extras — assigning the resource to one or
 * more named servers when more than one is configured, mapping the type to its
 * Doctrine entity, or future per-resource overrides.
 *
 * `server` names the server(s) this resource is exposed on: a single server name,
 * a list of names (the same type may join several servers at once), or `null` for
 * the implicit `default` server.
 *
 * The resource `type` is normally read from the class's static `$type`; the optional
 * `type` here is a declaration-site override for the rare case that differs.
 *
 * `entity` maps the resource type to the Doctrine entity class the reference
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider} reads
 * (and, in later phases, writes) — co-located here because the resource
 * declaration is the one place that already knows what it represents. It is
 * inert unless the Doctrine provider is wired (`doctrine/orm` installed).
 *
 * `serializer` / `hydrator` override how this type is serialized / hydrated: a
 * resource declares a custom {@see \haddowg\JsonApi\Serializer\SerializerInterface}
 * and/or {@see \haddowg\JsonApi\Hydrator\HydratorInterface} (each a registered
 * service, so it may have constructor dependencies) when the field DSL cannot
 * express the wire shape. The generic CRUD engine then drives reads/writes for
 * the type through the override instead of the resource's field inventory
 * (bundle ADR 0023).
 *
 * `operations` is the exposed operation allow-list: the {@see \haddowg\JsonApiBundle\Operation\Operation}
 * cases this type serves, one route emitted per case (bundle ADR 0025). An empty
 * array means the default — for a resource, all five operations.
 *
 * `readOnly` is an intent-named shorthand for the common "suppress every write"
 * case: `readOnly: true` restricts the type to the two fetch operations
 * ({@see \haddowg\JsonApiBundle\Operation\Operation::FetchCollection} and
 * {@see \haddowg\JsonApiBundle\Operation\Operation::FetchOne}) without importing the
 * enum or spelling them out. It is mutually exclusive with a non-empty `operations`
 * list (the precise escape hatch): declaring both is a constructor `\LogicException`,
 * so an ambiguous declaration never compiles.
 *
 * `cacheHeaders` declares **declarative HTTP cache headers** for the type's safe
 * (`GET`) reads (bundle ADR 0054, API-Platform gap G7): a map of `max_age`,
 * `s_maxage` (shared/CDN), `public`/`private`, `no_cache`, `must_revalidate` and
 * `vary` (a list of response header names → `Vary`). The
 * {@see \haddowg\JsonApiBundle\EventListener\ResponseHeadersListener} maps them
 * onto the `Cache-Control` + `Vary` headers of a **successful `GET`** response only
 * — never a write or an error. An optional nested `operations` key carries
 * per-read-shape overrides (`collection`/`read`/`related`/`relationship`), each the
 * same map, layered over the resource-level value. A resource that declares none
 * (and has no `json_api.defaults.cache_headers`) gets no `Cache-Control` (unchanged).
 *
 * `tags` declares the **OpenAPI tag names** every operation of this type is grouped
 * under in the generated OpenAPI document (design §4.7, D15). Tags carry **no
 * JSON:API meaning** — they only drive how Swagger UI / ReDoc group operations. An
 * empty array means the default: a single tag named the humanized, title-cased,
 * pluralized form of the type (e.g. `blog-post` -> `'Blog Posts'`). Tag *definitions*
 * (description / externalDocs / ordering) are config-authoritative; a
 * referenced-but-undefined tag is auto-synthesized name-only.
 *
 * `description` and `operationDescriptions` declare **OpenAPI description overrides**
 * (bundle ADR 0092): `description` replaces the generated default on the type's
 * **resource-object** component schema; `operationDescriptions` is a map keyed by the
 * {@see \haddowg\JsonApiBundle\Operation\Operation} case name (e.g.
 * `Operation::Create->name`, since an enum cannot be a PHP array key) overriding the
 * generated default on that one CRUD operation. An unknown key is a constructor
 * `\LogicException`. Both default to the generator's sensible generated
 * default when absent. They are the declaration-site equivalent of overriding the
 * resource's {@see \haddowg\JsonApi\Resource\AbstractResource::getDescription()} /
 * {@see \haddowg\JsonApi\Resource\AbstractResource::describeOperation()} method hooks
 * — when both are present the **method hook wins** (it is the more specific, runtime
 * surface). To describe a *relationship*'s related/relationship operations, call
 * `->describedAs('…')` on the relation field itself.
 *
 * `deprecation` and `sunset` declare **deprecation signalling** — the IETF
 * Deprecation header field (`draft-ietf-httpapi-deprecation-header`) plus the
 * RFC 8594 `Sunset` header (bundle ADR 0054, API-Platform gap G16), emitted on
 * **every** response for the type (reads and writes alike). `deprecation: true`
 * emits a bare `Deprecation: true`;
 * `deprecation: '<date>'` emits `Deprecation: <date>` (the author formats the date
 * per the RFC). `sunset: '<HTTP-date>'` emits `Sunset: <HTTP-date>`, plus — when
 * `sunsetLink` is set — a companion `Link: <uri>; rel="sunset"`.
 *
 * `security` (and the per-operation overrides) declare **declarative authorization**
 * for the type (bundle ADR 0043). Each accepts an **expression**, a **bool**, or null:
 *  - a Symfony Security
 *    {@see https://symfony.com/doc/current/security/expressions.html ExpressionLanguage}
 *    string is evaluated at the matching lifecycle hook against the subject entity (it
 *    sees `user`/`object`/`request`/`token`/`roles` and `is_granted()` etc.); a false
 *    result denies the operation with a `403`. The operation is also documented as
 *    secured (OpenAPI `security` + a `401` response).
 *  - **`true`** documents the operation as secured (OpenAPI `security` + `401`) **without**
 *    a bundle-evaluated expression — for an operation an external firewall protects, so
 *    the document reflects the auth the firewall enforces.
 *  - **`false`** documents the operation as **public** (OpenAPI `security: []`, no `401`),
 *    overriding the document-level default security regardless of what it is.
 *  - **null** leaves the operation to inherit: ungated by this layer, and documented
 *    against the document-level default.
 *
 * `security` is the default applied to every operation; `securityCreate`/`securityUpdate`/
 * `securityDelete`/`securityRead` override it for one operation (each falling back to
 * `security` when null). `securityUpdate` also gates relationship mutation. A bool is a
 * **documentation-only** declaration — only an expression is enforced at runtime, and
 * only when `symfony/security-core` is installed.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiResource
{
    /**
     * @param string|list<string>|null                                             $server         the server name(s) exposing this type (null = the implicit `default`)
     * @param class-string|null                                                    $entity         the Doctrine entity backing this resource type
     * @param class-string<\haddowg\JsonApi\Serializer\SerializerInterface>|null    $serializer     a custom serializer for this type
     * @param class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>|null        $hydrator       a custom hydrator for this type
     * @param list<\haddowg\JsonApiBundle\Operation\Operation>                      $operations     the exposed operation allow-list (empty = all five); mutually exclusive with `readOnly`
     * @param bool                                                                 $readOnly       shorthand restricting the type to the two fetch operations; mutually exclusive with a non-empty `operations`
     * @param string|bool|null                                                     $security       the default security for every operation: an expression (enforced + documented secured), `true` (documented secured only), `false` (documented public), or null (inherit)
     * @param string|bool|null                                                     $securityCreate security for create (expression/`true`/`false`; falls back to `security`)
     * @param string|bool|null                                                     $securityUpdate security for update and relationship mutation (expression/`true`/`false`; falls back to `security`)
     * @param string|bool|null                                                     $securityDelete security for delete (expression/`true`/`false`; falls back to `security`)
     * @param string|bool|null                                                     $securityRead   security for a single-resource read (expression/`true`/`false`; falls back to `security`)
     * @param array<string, mixed>                                                 $cacheHeaders   declarative HTTP cache directives for GET reads (`max_age`/`s_maxage`/`public`/`private`/`no_cache`/`must_revalidate`/`vary`), with an optional nested `operations` per-read-shape override map; empty = none
     * @param bool|string|null                                                     $deprecation    IETF Deprecation-header deprecation: `true` (bare header), a date string (`Deprecation: <date>`), or null (none)
     * @param string|null                                                          $sunset         RFC 8594 sunset HTTP-date (`Sunset: <date>`), or null
     * @param string|null                                                          $sunsetLink     a URI for the companion `Link: <uri>; rel="sunset"` (emitted only when `sunset` is set)
     * @param list<string>                                                         $tags           the OpenAPI tag names every operation of this type is grouped under (empty = the humanized-type default)
     * @param string|null                                                          $description    the OpenAPI description override for this type's resource-object schema (null = the generated default)
     * @param array<string, string>                                                $operationDescriptions per-CRUD-operation OpenAPI description overrides, keyed by the {@see \haddowg\JsonApiBundle\Operation\Operation} case name (e.g. `Operation::Create->name`); an unknown key is a constructor error
     */
    public function __construct(
        public ?string $type = null,
        public string|array|null $server = null,
        public ?string $entity = null,
        public ?string $serializer = null,
        public ?string $hydrator = null,
        public array $operations = [],
        public bool $readOnly = false,
        public string|bool|null $security = null,
        public string|bool|null $securityCreate = null,
        public string|bool|null $securityUpdate = null,
        public string|bool|null $securityDelete = null,
        public string|bool|null $securityRead = null,
        public array $cacheHeaders = [],
        public bool|string|null $deprecation = null,
        public ?string $sunset = null,
        public ?string $sunsetLink = null,
        public array $tags = [],
        public ?string $description = null,
        public array $operationDescriptions = [],
    ) {
        if ($readOnly && $operations !== []) {
            throw new \LogicException(
                'AsJsonApiResource declares both readOnly: true and a non-empty operations list; '
                . 'they are mutually exclusive — drop one. Use readOnly for the two fetch operations, '
                . 'or operations for a precise allow-list.',
            );
        }

        foreach (\array_keys($operationDescriptions) as $key) {
            if (!\is_string($key) || Operation::tryFrom($key) === null) {
                throw new \LogicException(\sprintf(
                    'AsJsonApiResource operationDescriptions has an unknown operation key "%s"; '
                    . 'keys must be a %s case name: %s.',
                    (string) $key,
                    Operation::class,
                    \implode(', ', \array_map(static fn(Operation $op): string => $op->name, Operation::cases())),
                ));
            }
        }
    }
}
