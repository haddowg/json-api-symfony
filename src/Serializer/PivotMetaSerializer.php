<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\DataProvider\PivotFields;

/**
 * A {@see SerializerInterface} decorator that renders a `belongsToMany` pivot
 * relation's per-member pivot values as `meta.pivot`, mirroring how
 * {@see \haddowg\JsonApi\Serializer\PolymorphicSerializer} decorates a polymorphic
 * relation. It wraps the related type's real serializer and delegates everything to
 * it EXCEPT {@see getMeta()}, where it merges the member's pivot values
 * (`pivotMap[id]`, read from the SAME association-entity query) under a top-level
 * `pivot` key into the inner serializer's meta.
 *
 * Pivot meta rides core's existing `getMeta()` path, which the transformer renders
 * into BOTH the full resource (the related endpoint `GET /{type}/{id}/{rel}`) and
 * the resource identifier (the relationship-linkage endpoint
 * `GET /{type}/{id}/relationships/{rel}`) — so binding this serializer for those two
 * renders needs no core change. A member with no pivot entry (it should not happen
 * for a pivot collection, but is possible for the relationship endpoint's full
 * association) renders the inner meta unchanged.
 *
 * The `pivot` key keeps the values namespaced so they never collide with the
 * member resource's own meta, and so a client can tell pivot data from intrinsic
 * meta.
 *
 * Given the relation, it renders only the pivot fields a response may carry: a
 * hidden field or one write-only for this request (`writeOnly()`, or a
 * `writeOnly(when:)` predicate evaluated against the request) stays out, the same
 * rule a top-level attribute follows. The map itself keeps them, because the
 * validator reads stored write-only values when it merges a partial pivot update.
 */
final class PivotMetaSerializer implements SerializerInterface, IncludeControlsInterface
{
    /**
     * @param SerializerInterface                  $inner    the related type's real serializer
     * @param array<string, array<string, mixed>>  $pivotMap `memberId => [pivotField => typed value]`
     * @param RelationInterface|null               $relation the pivot relation whose fields decide what renders; without it the map renders as given
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly array $pivotMap,
        private readonly ?RelationInterface $relation = null,
    ) {}

    public function getType(mixed $object): string
    {
        return $this->inner->getType($object);
    }

    public function getId(mixed $object): string
    {
        return $this->inner->getId($object);
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        $meta = $this->inner->getMeta($object, $request);

        $pivot = $this->pivotMap[$this->inner->getId($object)] ?? null;
        if ($pivot !== null && $this->relation !== null) {
            $pivot = $this->readable($pivot, $request);
        }
        if ($pivot !== null && $pivot !== []) {
            $meta['pivot'] = $pivot;
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $pivot
     *
     * @return array<string, mixed>
     */
    private function readable(array $pivot, JsonApiRequestInterface $request): array
    {
        \assert($this->relation !== null);

        $readable = [];
        foreach (PivotFields::byName($this->relation) as $name => $field) {
            if (!\array_key_exists($name, $pivot) || $field->isHidden() || $field->isWriteOnlyFor($request)) {
                continue;
            }
            $readable[$name] = $pivot[$name];
        }

        return $readable;
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->inner->getLinks($object, $request);
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getAttributes($object, $request);
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->inner->getDefaultIncludedRelationships($object);
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getRelationships($object, $request);
    }

    public function getNonIncludableRelationships(JsonApiRequestInterface $request, mixed $object): array
    {
        return $this->inner instanceof IncludeControlsInterface
            ? $this->inner->getNonIncludableRelationships($request, $object)
            : [];
    }

    public function maxIncludeDepth(): ?int
    {
        return $this->inner instanceof IncludeControlsInterface
            ? $this->inner->maxIncludeDepth()
            : null;
    }

    public function getAllowedIncludePaths(): ?array
    {
        return $this->inner instanceof IncludeControlsInterface
            ? $this->inner->getAllowedIncludePaths()
            : null;
    }
}
