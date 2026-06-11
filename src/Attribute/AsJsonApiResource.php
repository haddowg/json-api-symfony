<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

/**
 * Optional metadata for a JSON:API Resource service. Discovery is zero-config by
 * default (any {@see \haddowg\JsonApi\Resource\AbstractResource} is auto-registered);
 * this attribute is only needed to carry extras — assigning the resource to a named
 * server/version when more than one is configured, mapping the type to its Doctrine
 * entity, or future per-resource overrides.
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
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiResource
{
    /**
     * @param class-string|null                                                    $entity     the Doctrine entity backing this resource type
     * @param class-string<\haddowg\JsonApi\Serializer\SerializerInterface>|null    $serializer a custom serializer for this type
     * @param class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>|null        $hydrator   a custom hydrator for this type
     */
    public function __construct(
        public ?string $type = null,
        public ?string $server = null,
        public ?string $entity = null,
        public ?string $serializer = null,
        public ?string $hydrator = null,
    ) {}
}
