<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

/**
 * The inner-query shape descriptor {@see WindowedRelationBatch} resolves per relation
 * (bundle ADR 0066): which DQL the bounded ROW_NUMBER window wraps, mirroring
 * {@see RelationScope}'s two branches.
 *
 *  - **inverse-FK** ({@see inverseFk()}) — a single-valued inverse association (the
 *    related entity carries the owning FK). The related entity is the inner DQL ROOT,
 *    scoped by the {@see $owningField} FK `IN` the page with the FK projected as the
 *    parent discriminator; the related entity hydrates INLINE (a member belongs to one
 *    parent, so no cross-partition root-entity dedup) in ONE statement.
 *  - **join-table** ({@see joinTable()}) — an owning-side / many-to-many relation. The
 *    PARENT is the inner DQL root, the related collection joined as `related`, and the
 *    inner query selects the scalar `(parentId, relatedId)` PAIRS plus each sort column
 *    as a scalar (the ORM object hydrator dedups a member shared across parents and would
 *    lose a partition). The provider id-loads the distinct related entities by id in ONE
 *    further query — two statements, still bounded and O(1) per relation.
 */
final readonly class WindowShape
{
    /**
     * @param ?string       $owningField    the inverse-FK shape's owning FK field on the related entity
     * @param ?string       $property       the join-table shape's parent association property
     * @param ?class-string $parentClass    the join-table shape's parent entity class (the inner DQL root)
     * @param ?string       $parentIdField  the join-table shape's parent single id field
     * @param ?string       $relatedIdField the join-table shape's related single id field (the id-load + pk tiebreak)
     */
    private function __construct(
        public bool $joinTable,
        public ?string $owningField = null,
        public ?string $property = null,
        public ?string $parentClass = null,
        public ?string $parentIdField = null,
        public ?string $relatedIdField = null,
    ) {}

    /**
     * The inverse-FK shape: the related entity is the inner DQL root, scoped by
     * `$owningField`.
     */
    public static function inverseFk(string $owningField): self
    {
        return new self(joinTable: false, owningField: $owningField);
    }

    /**
     * The join-table / many-to-many shape: the parent is the inner DQL root, the related
     * collection joined as `related`.
     *
     * @param class-string $parentClass
     */
    public static function joinTable(
        string $property,
        string $parentClass,
        string $parentIdField,
        string $relatedIdField,
    ): self {
        return new self(
            joinTable: true,
            property: $property,
            parentClass: $parentClass,
            parentIdField: $parentIdField,
            relatedIdField: $relatedIdField,
        );
    }

    /**
     * Whether this is the join-table (owning-side / many-to-many) shape, which selects the
     * scalar `(parentId, relatedId)` pairs and id-loads the distinct related entities to
     * defeat the ORM cross-partition root-entity dedup.
     */
    public function isJoinTable(): bool
    {
        return $this->joinTable;
    }
}
