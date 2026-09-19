<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Query;

use haddowg\JsonApi\OpenApi\QueryParameterShape;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Filter\DescribedFilter;
use haddowg\JsonApi\Resource\Filter\DescribesQueryParameter;

/**
 * A demonstrator **custom full-text filter**: `filter[<key>]=term` keeps a resource
 * whose any of the declared text `$fields` contains `term` (case-insensitive
 * substring). It is neither a `Where` nor any built-in — it exists to show the
 * extensible-filter seam: the built-in handlers don't recognise it, so it runs only
 * because a registered arm ({@see DoctrineFullTextSearchArm}) teaches the provider to
 * execute it. The example serves over Doctrine, so it ships the Doctrine arm only; a
 * portable filter would additionally ship an `ArrayFilterArmInterface` witness.
 *
 * Two opt-in OpenAPI seams carry the rest of the declaration, because a custom filter
 * gets nothing by accident:
 *
 *  - {@see DescribedFilter} surfaces a meaningful description on the `filter[<key>]`
 *    parameter rather than the generic per-key default.
 *  - {@see DescribesQueryParameter} types the value. The projector defaults an
 *    unconstrained filter's value from the single column the filter targets (core ADR
 *    0138), and this one targets several — so there is no column to read a type off,
 *    and core honestly leaves the value untyped. Describing the parameter is how a
 *    multi-column filter says what only it can know: the wire value is one search
 *    string.
 */
final class FullTextSearch implements DescribedFilter, DescribesQueryParameter
{
    /**
     * @param list<string> $fields the entity field names searched (OR-ed together)
     */
    private function __construct(
        private readonly string $key,
        public readonly array $fields,
    ) {}

    /**
     * @param list<string> $fields the entity field names searched (OR-ed together)
     */
    public static function make(string $key, array $fields): self
    {
        return new self($key, $fields);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function constraints(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return \sprintf(
            'Case-insensitive substring search across %s.',
            \implode(', ', $this->fields),
        );
    }

    /**
     * The search term is a plain scalar, so the parameter keeps the projector's default
     * envelope (no `style`/`explode`) and only gains a type. `$valueSchema` is whatever
     * {@see constraints()} projected — empty here, but adding the type rather than
     * replacing the schema is the habit that keeps a later constraint from being
     * silently dropped.
     */
    public function describeQueryParameter(Schema $valueSchema): QueryParameterShape
    {
        return new QueryParameterShape($valueSchema->withType('string'));
    }
}
