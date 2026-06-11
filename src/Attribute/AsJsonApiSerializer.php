<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Attribute;

/**
 * Registers the annotated {@see \haddowg\JsonApi\Serializer\SerializerInterface}
 * as the serializer for a JSON:API `type`, **without** an
 * {@see \haddowg\JsonApi\Resource\AbstractResource} (bundle ADR 0024). A type
 * registered this way is **serialize-only** by default: it renders as primary
 * data, linkage and `included`, but exposes no endpoints of its own — the classic
 * embedded / reference type that only ever appears inside another resource.
 *
 * `AbstractResource` is the preferred sugar (it supplies serializer + hydrator +
 * relations + the fields DSL from one declaration); this is the decoupled path for
 * a type whose wire shape is fully hand-written, or that has no resource at all.
 * Pair it with {@see AsJsonApiHydrator} (and a provider/persister) to make the
 * type writable / fetchable; expose endpoints with the `operations` allow-list
 * (a later slice).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiSerializer
{
    public function __construct(public string $type) {}
}
