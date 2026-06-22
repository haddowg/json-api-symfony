<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

/**
 * Builds the JSON:API `source.pointer` (RFC 6901) a validation error should carry
 * from a Symfony violation's property path. Core has no such helper — it only
 * offers the `ErrorSource::fromPointer(string)` sink — so the bridge owns the
 * mapping.
 *
 * The bridge validates a resource's `attributes` array, so a violation's property
 * path is a bracketed key path (`[title]`, or `[address][city]` for a nested
 * {@see \haddowg\JsonApi\Resource\Field\Map}); each becomes a segment under
 * `/data/attributes`. An empty path (a document-level violation) points at
 * `/data/attributes` itself.
 */
final class JsonPointerBuilder
{
    private const string ATTRIBUTES_BASE = '/data/attributes';

    /**
     * The pointer for a violation on the resource `attributes`, from its Symfony
     * property path (`[title]`, `[address][city]`, or `''`).
     */
    public function forAttribute(string $propertyPath): string
    {
        \preg_match_all('/\[([^\]]*)\]/', $propertyPath, $matches);

        $segments = \array_map([$this, 'encodeSegment'], $matches[1]);

        return $segments === []
            ? self::ATTRIBUTES_BASE
            : self::ATTRIBUTES_BASE . '/' . \implode('/', $segments);
    }

    /**
     * The pointer for a violation on a relationship **linkage** id: the to-one
     * linkage points at `/data/relationships/<rel>/data/id`, and a to-many member
     * at `/data/relationships/<rel>/data/<index>/id` (the index supplied for a
     * to-many element, omitted for a to-one).
     */
    public function forLinkageId(string $relation, ?int $index = null): string
    {
        $base = '/data/relationships/' . $this->encodeSegment($relation) . '/data';
        if ($index !== null) {
            $base .= '/' . $index;
        }

        return $base . '/id';
    }

    /**
     * The pointer for a violation on a relationship **linkage** `type`: the to-one
     * linkage points at `/data/relationships/<rel>/data/type`, and a to-many member
     * at `/data/relationships/<rel>/data/<index>/type` (the index supplied for a
     * to-many element, omitted for a to-one). Locates the offending linkage when its
     * resource `type` is not an accepted related type of the relation.
     */
    public function forLinkageType(string $relation, ?int $index = null): string
    {
        $base = '/data/relationships/' . $this->encodeSegment($relation) . '/data';
        if ($index !== null) {
            $base .= '/' . $index;
        }

        return $base . '/type';
    }

    /**
     * The pointer for a violation on a linkage id at a **relationship-mutation
     * endpoint** (`PATCH`/`POST`/`DELETE …/relationships/<rel>`), where the request
     * body root *is* the relationship object: a to-one points at `/data/id`, a
     * to-many member at `/data/<index>/id` (the index omitted for a to-one).
     */
    public function forRelationshipEndpointLinkageId(?int $index = null): string
    {
        return $index === null ? '/data/id' : '/data/' . $index . '/id';
    }

    /**
     * The pointer for a violation on a linkage `type` at a **relationship-mutation
     * endpoint** (`PATCH`/`POST`/`DELETE …/relationships/<rel>`), where the request
     * body root *is* the relationship object: a to-one points at `/data/type`, a
     * to-many member at `/data/<index>/type` (the index omitted for a to-one).
     */
    public function forRelationshipEndpointLinkageType(?int $index = null): string
    {
        return $index === null ? '/data/type' : '/data/' . $index . '/type';
    }

    /**
     * The pointer for a violation on a relationship linkage member's pivot `meta`
     * field in a **whole-resource write**: the to-one member points at
     * `/data/relationships/<rel>/data/meta/<field>` and a to-many member at
     * `/data/relationships/<rel>/data/<index>/meta/<field>` (the index supplied for a
     * to-many element, omitted for a to-one). `$bracketedField` is the Symfony
     * Collection property path of the offending meta key (`[position]`).
     */
    public function forLinkageMeta(string $relation, string $bracketedField, ?int $index = null): string
    {
        $base = '/data/relationships/' . $this->encodeSegment($relation) . '/data';
        if ($index !== null) {
            $base .= '/' . $index;
        }

        return $base . '/meta' . $this->metaSuffix($bracketedField);
    }

    /**
     * The pointer for a violation on a linkage member's pivot `meta` field at a
     * **relationship-mutation endpoint** (`PATCH`/`POST …/relationships/<rel>`),
     * where the request body root *is* the relationship object: a to-one member
     * points at `/data/meta/<field>`, a to-many member at `/data/<index>/meta/<field>`
     * (the index omitted for a to-one).
     */
    public function forRelationshipEndpointLinkageMeta(string $bracketedField, ?int $index = null): string
    {
        $base = $index === null ? '/data' : '/data/' . $index;

        return $base . '/meta' . $this->metaSuffix($bracketedField);
    }

    /**
     * The `/<field>…` suffix for a pivot-meta pointer, from a Symfony Collection
     * property path (`[position]` → `/position`); an empty path (a meta-level
     * violation) yields an empty suffix so the pointer ends at `…/meta`.
     */
    private function metaSuffix(string $bracketedPath): string
    {
        \preg_match_all('/\[([^\]]*)\]/', $bracketedPath, $matches);

        $segments = \array_map([$this, 'encodeSegment'], $matches[1]);

        return $segments === [] ? '' : '/' . \implode('/', $segments);
    }

    /**
     * Escapes a single JSON Pointer reference token per RFC 6901: `~` → `~0`,
     * `/` → `~1`.
     */
    private function encodeSegment(string $segment): string
    {
        return \str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
