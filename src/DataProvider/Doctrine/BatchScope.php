<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\QueryBuilder;

/**
 * The descriptor {@see RelationScope::scopeBatchToParents()} returns for a batched
 * related fetch (Approach B, bundle ADR 0061): the query scoped to a page of
 * parents, plus the metadata the caller needs to turn its result into a per-parent
 * partition. Both shapes apply the shared criteria on {@see $relatedAlias}, but they
 * differ in how the materialized result carries the (parent, related) pairing —
 * driven by whether a related entity can be a member of more than one parent:
 *
 *  - **Inverse-FK shape** ({@see $relatedClass} null) — the related entity is the
 *    query ROOT and the parent storage id rides each row as the scalar
 *    {@see PARENT_DISCRIMINATOR_ALIAS}, so a row hydrates
 *    `[0 => relatedEntity, 'jsonapi_parent_id' => parentStorageId]`. Safe because a
 *    single-valued inverse relation's related entity belongs to exactly one parent —
 *    no related entity repeats across rows, so ORM root-entity dedup never bites. ONE
 *    query.
 *  - **Pair shape** ({@see $relatedClass} set) — the PARENT is the query root and the
 *    query SELECTs only the scalar `(parentId, relatedId)` pairs (the parent id under
 *    {@see PARENT_DISCRIMINATOR_ALIAS}, the related id under
 *    {@see RELATED_DISCRIMINATOR_ALIAS}). Used for an owning-side / many-to-many
 *    relation whose related entity may be a member of more than one parent: scalars
 *    never dedup, so the filtered/ordered membership is exact. The caller then loads
 *    the distinct related entities of {@see $relatedClass} by id in ONE further
 *    IN-query (keyed by {@see $relatedIdField}) and re-associates them per pair —
 *    two scalar+load queries, still O(N) per relation.
 */
final readonly class BatchScope
{
    /** The scalar alias both shapes project the parent storage id under. */
    public const string PARENT_DISCRIMINATOR_ALIAS = 'jsonapi_parent_id';

    /** The scalar alias the pair shape projects the related storage id under. */
    public const string RELATED_DISCRIMINATOR_ALIAS = 'jsonapi_related_id';

    /**
     * @param ?class-string $relatedClass   the related entity class to id-load (the pair shape), or null
     *                                       for the inverse-FK shape (the related entity is the query root)
     * @param ?string       $relatedIdField the related entity's single id field, for the pair shape's id-load
     */
    public function __construct(
        public QueryBuilder $builder,
        public string $relatedAlias,
        public ?string $relatedClass = null,
        public ?string $relatedIdField = null,
    ) {}
}
