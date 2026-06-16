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
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiResource
{
    /**
     * @param string|list<string>|null                                             $server     the server name(s) exposing this type (null = the implicit `default`)
     * @param class-string|null                                                    $entity     the Doctrine entity backing this resource type
     * @param class-string<\haddowg\JsonApi\Serializer\SerializerInterface>|null    $serializer a custom serializer for this type
     * @param class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>|null        $hydrator   a custom hydrator for this type
     * @param list<\haddowg\JsonApiBundle\Operation\Operation>                      $operations the exposed operation allow-list (empty = all five)
     */
    public function __construct(
        public ?string $type = null,
        public string|array|null $server = null,
        public ?string $entity = null,
        public ?string $serializer = null,
        public ?string $hydrator = null,
        public array $operations = [],
    ) {}
}
