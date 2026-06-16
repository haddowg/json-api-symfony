<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Http;

use haddowg\JsonApi\Operation\Target;

/**
 * The read shapes a per-operation cache-header override can key on (bundle ADR
 * 0054). Cache headers apply only to safe (`GET`) reads, and a JSON:API type
 * serves four of them — the primary collection, a single resource, a related
 * resource(s) endpoint, and a relationship linkage endpoint — so a resource can
 * tune caching per shape (e.g. a long-lived `collection` vs a short-lived
 * `relationship`).
 *
 * Each case value equals its name so a per-operation override map flows through
 * the container as plain case-value-string keys (objects are not dumpable).
 */
enum ResponseHeaderOperation: string
{
    case Collection = 'collection';
    case Read = 'read';
    case Related = 'related';
    case Relationship = 'relationship';

    /**
     * The shape a `GET` {@see Target} reads as: a relationship-linkage endpoint is
     * {@see self::Relationship}; a related endpoint {@see self::Related}; a target
     * carrying an `{id}` {@see self::Read}; otherwise the collection.
     */
    public static function fromTarget(Target $target): self
    {
        if ($target->relationship !== null) {
            return $target->isRelationshipEndpoint ? self::Relationship : self::Related;
        }

        return $target->id !== null ? self::Read : self::Collection;
    }
}
