<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

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
 * `security` (and the per-operation overrides) declare **declarative authorization**
 * for the type (bundle ADR 0043): each is a Symfony Security
 * {@see https://symfony.com/doc/current/security/expressions.html ExpressionLanguage}
 * string evaluated at the matching lifecycle hook against the subject entity. The
 * expression sees the standard security variables (`user`, `object`, `request`,
 * `token`, `roles`) and functions (`is_granted()`, `is_authenticated_fully()`, …);
 * when it evaluates to false the operation is denied with a `403`. `security` is the
 * default applied to every gated operation; `securityCreate`/`securityUpdate`/
 * `securityDelete`/`securityRead` override it for one operation (each falling back to
 * `security` when null). `securityUpdate` also gates relationship mutation. A `null`
 * expression (after the fallback) leaves that operation **ungated** by this layer.
 * Authorization is inert unless `symfony/security-core` is installed.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiResource
{
    /**
     * @param string|list<string>|null                                             $server         the server name(s) exposing this type (null = the implicit `default`)
     * @param class-string|null                                                    $entity         the Doctrine entity backing this resource type
     * @param class-string<\haddowg\JsonApi\Serializer\SerializerInterface>|null    $serializer     a custom serializer for this type
     * @param class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>|null        $hydrator       a custom hydrator for this type
     * @param list<\haddowg\JsonApiBundle\Operation\Operation>                      $operations     the exposed operation allow-list (empty = all five)
     * @param string|null                                                          $security       the default security expression applied to every gated operation (null = ungated)
     * @param string|null                                                          $securityCreate the security expression for create (falls back to `security`)
     * @param string|null                                                          $securityUpdate the security expression for update and relationship mutation (falls back to `security`)
     * @param string|null                                                          $securityDelete the security expression for delete (falls back to `security`)
     * @param string|null                                                          $securityRead   the security expression for a single-resource read (falls back to `security`)
     */
    public function __construct(
        public ?string $type = null,
        public string|array|null $server = null,
        public ?string $entity = null,
        public ?string $serializer = null,
        public ?string $hydrator = null,
        public array $operations = [],
        public ?string $security = null,
        public ?string $securityCreate = null,
        public ?string $securityUpdate = null,
        public ?string $securityDelete = null,
        public ?string $securityRead = null,
    ) {}
}
