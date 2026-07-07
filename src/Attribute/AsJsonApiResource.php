<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\CreateResponse;
use haddowg\JsonApi\OpenApi\Metadata\DeleteResponse;
use haddowg\JsonApi\OpenApi\Metadata\FetchCollectionResponse;
use haddowg\JsonApi\OpenApi\Metadata\FetchOneResponse;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponses;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\UpdateResponse;
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
 * `security` is the default applied to **every** operation — including the collection
 * read; `securityCreate`/`securityUpdate`/`securityDelete`/`securityRead`/`securityList`
 * override it for one operation (each falling back to `security` when null).
 * `securityUpdate` also gates relationship mutation. `securityRead` gates the single read
 * (`GET /{type}/{id}`, against the loaded entity); `securityList` gates the collection
 * read (`GET /{type}`) as an all-or-nothing blanket gate evaluated **before** the query
 * with no subject (a role/attribute check) — row-level read filtering still belongs in
 * the query scope. A bool is a **documentation-only** declaration — only an expression is
 * enforced at runtime, and only when `symfony/security-core` is installed.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiResource
{
    /**
     * The declared per-operation OpenAPI success responses (core PR — typed response
     * objects, self-validating: only spec-valid codes are constructible, a `202`
     * carries its job type). Each is normalized to a list (`[]` = the operation's
     * default), validated via {@see OperationResponses::validate()}, and an override
     * for an operation the type does not expose is a constructor `\LogicException`.
     *
     * @var list<CreateResponse>
     */
    public array $create;

    /** @var list<UpdateResponse> */
    public array $update;

    /** @var list<DeleteResponse> */
    public array $delete;

    /** @var list<FetchOneResponse> */
    public array $fetchOne;

    /** @var list<FetchCollectionResponse> */
    public array $fetchCollection;

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
     * @param string|bool|null                                                     $securityRead   security for a single-resource read `GET /{type}/{id}` (expression/`true`/`false`; falls back to `security`)
     * @param string|bool|null                                                     $securityList   security for the collection read `GET /{type}` (expression/`true`/`false`; falls back to `security`). An expression is evaluated with no subject (use a role/attribute check) and gates the whole collection BEFORE the query
     * @param array<string, mixed>                                                 $cacheHeaders   declarative HTTP cache directives for GET reads (`max_age`/`s_maxage`/`public`/`private`/`no_cache`/`must_revalidate`/`vary`), with an optional nested `operations` per-read-shape override map; empty = none
     * @param bool|string|null                                                     $deprecation    IETF Deprecation-header deprecation: `true` (bare header), a date string (`Deprecation: <date>`), or null (none)
     * @param string|null                                                          $sunset         RFC 8594 sunset HTTP-date (`Sunset: <date>`), or null
     * @param string|null                                                          $sunsetLink     a URI for the companion `Link: <uri>; rel="sunset"` (emitted only when `sunset` is set)
     * @param list<string>                                                         $tags           the OpenAPI tag names every operation of this type is grouped under (empty = the humanized-type default)
     * @param string|null                                                          $description    the OpenAPI description override for this type's resource-object schema (null = the generated default)
     * @param array<string, string>                                                $operationDescriptions per-CRUD-operation OpenAPI description overrides, keyed by the {@see \haddowg\JsonApiBundle\Operation\Operation} case name (e.g. `Operation::Create->name`); an unknown key is a constructor error
     * @param CreateResponse|list<CreateResponse>|null                             $create         the declared success responses for `POST` create (single or list; null = the default `201`). `new Accepted($jobType)` documents an async `202`; `new NoContent()` a client-id `204`
     * @param UpdateResponse|list<UpdateResponse>|null                             $update         the declared success responses for `PATCH` update (null = the default `200`)
     * @param DeleteResponse|list<DeleteResponse>|null                             $delete         the declared success responses for `DELETE` (null = the default `204`)
     * @param FetchOneResponse|list<FetchOneResponse>|null                         $fetchOne       the declared success responses for `GET /{type}/{id}` (null = the default `200`; add `new SeeOther()` for a `303` async-completion redirect)
     * @param FetchCollectionResponse|list<FetchCollectionResponse>|null           $fetchCollection the declared success responses for `GET /{type}` (null = the default `200`)
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
        public string|bool|null $securityList = null,
        public array $cacheHeaders = [],
        public bool|string|null $deprecation = null,
        public ?string $sunset = null,
        public ?string $sunsetLink = null,
        public array $tags = [],
        public ?string $description = null,
        public array $operationDescriptions = [],
        CreateResponse|array|null $create = null,
        UpdateResponse|array|null $update = null,
        DeleteResponse|array|null $delete = null,
        FetchOneResponse|array|null $fetchOne = null,
        FetchCollectionResponse|array|null $fetchCollection = null,
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

        // The operations this type exposes (the response-override target set): the
        // readOnly shorthand, an explicit allow-list, or the all-five default.
        $allowed = $readOnly
            ? [Operation::FetchCollection->value, Operation::FetchOne->value]
            : ($operations !== []
                ? \array_map(static fn(Operation $op): string => $op->value, $operations)
                : \array_map(static fn(Operation $op): string => $op->value, Operation::cases()));

        $this->create = self::normalizeResponses($create, OperationType::Create, $allowed);
        $this->update = self::normalizeResponses($update, OperationType::Update, $allowed);
        $this->delete = self::normalizeResponses($delete, OperationType::Delete, $allowed);
        $this->fetchOne = self::normalizeResponses($fetchOne, OperationType::FetchOne, $allowed);
        $this->fetchCollection = self::normalizeResponses($fetchCollection, OperationType::FetchCollection, $allowed);
    }

    /**
     * Normalizes a declared response override to a list, validates it against the
     * operation's spec-valid set ({@see OperationResponses::validate()}), and rejects an
     * override for an operation this type does not expose. `null` yields `[]` (the
     * projector then emits the operation's default).
     *
     * @template T of OperationResponseInterface
     *
     * @param T|list<T>|null $declared
     * @param list<string>   $allowed  the exposed operation values ({@see Operation::value})
     *
     * @return list<T>
     */
    private static function normalizeResponses(OperationResponseInterface|array|null $declared, OperationType $operation, array $allowed): array
    {
        if ($declared === null) {
            return [];
        }

        $list = \is_array($declared) ? \array_values($declared) : [$declared];
        OperationResponses::validate($operation, $list);

        if (!\in_array($operation->value, $allowed, true)) {
            throw new \LogicException(\sprintf(
                'AsJsonApiResource declares a response override for the %s operation, but that operation is not '
                . 'exposed (it is excluded by operations/readOnly). Expose the operation or drop the override.',
                $operation->value,
            ));
        }

        return $list;
    }
}
