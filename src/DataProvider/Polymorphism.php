<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Field\PolymorphicRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The one rule for whether a relation is polymorphic, shared by the OpenAPI metadata and
 * every runtime read, so an endpoint accepts exactly the parameters its document lists.
 * A `MorphTo` or `MorphToMany` is polymorphic over a single type too (core ADR 0184), and
 * any relation listing several types is.
 *
 * It decides what a request may ask of the relation. How its members are loaded still
 * goes by the count of types: one type is one query whatever the relation is declared as.
 *
 * @internal
 */
final class Polymorphism
{
    public static function of(RelationInterface $relation): bool
    {
        return $relation instanceof PolymorphicRelationInterface || \count($relation->relatedTypes()) > 1;
    }

    /**
     * Whether `$relation` has one related type and is not polymorphic, so that type's
     * resource supplies its filter, sort and page vocabulary.
     */
    public static function isMonomorphic(RelationInterface $relation): bool
    {
        return \count($relation->relatedTypes()) === 1 && !self::of($relation);
    }
}
