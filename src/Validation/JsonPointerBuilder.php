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
     * Escapes a single JSON Pointer reference token per RFC 6901: `~` → `~0`,
     * `/` → `~1`.
     */
    private function encodeSegment(string $segment): string
    {
        return \str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
