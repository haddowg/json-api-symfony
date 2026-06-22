<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * Thrown when a relationship **linkage** identifier carries a resource `type` that
 * is not among the relation's declared related types ({@see \haddowg\JsonApi\Resource\Field\RelationInterface::relatedTypes()}).
 *
 * This is the relationship-linkage twin of core's create-path
 * {@see \haddowg\JsonApi\Exception\ResourceTypeUnacceptable}: a `POST /{type}` whose
 * `data.type` does not match the endpoint's accepted type is a `409 Conflict`
 * (`RESOURCE_TYPE_UNACCEPTABLE`, pointer `/data/type`), JSON:API's resource-type
 * conflict convention. A linkage `{ "type": T, "id": X }` whose `T` is not an
 * accepted inverse type of the relation is the same kind of conflict — the request
 * names a resource type the operation cannot accept — so it is rendered with the
 * same status and error code, but with a `source.pointer` locating the offending
 * relationship (`/data/relationships/<rel>` in a whole-resource write, the endpoint
 * linkage `/data` at a relationship-mutation endpoint).
 *
 * Core declares the accepted set on every relation (the
 * {@see \haddowg\JsonApi\Resource\Constraint\RelationshipType} VO it appends from
 * `relatedTypes()`) but never executes it; the bundle's {@see ResourceValidator}
 * enforces it. This is distinct from core's cardinality guard
 * {@see \haddowg\JsonApi\Exception\RelationshipTypeInappropriate} (to-one vs
 * to-many), which is about the *shape* of the linkage, not the resource type of its
 * identifiers.
 */
final class RelationshipTypeUnacceptable extends AbstractJsonApiException
{
    /**
     * @param list<Error> $errors one error per offending linkage identifier, each
     *                            with a source pointer locating the relationship
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The relationship linkage carries an unacceptable resource type.', 409);
    }

    /**
     * @return list<Error>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
