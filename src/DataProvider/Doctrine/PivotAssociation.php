<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

/**
 * The resolved Doctrine association entity backing a `belongsToMany` pivot
 * relation, plus the two single-valued (`ManyToOne`) associations on that entity
 * that reach the parent and the far type.
 *
 * A plain `#[ORM\ManyToMany]` join table holds only the two foreign keys, so it
 * cannot carry a pivot column (`position`, `addedAt`, …). To HAVE pivot fields
 * the join must be modelled as an **association entity** — `PlaylistTrack {
 * position; addedAt; ManyToOne playlist; ManyToOne track }` — with the parent
 * owning a `OneToMany` to it and the entity a `ManyToOne` to the far type. This
 * value object captures that triple so the {@see DoctrineDataProvider} can run the
 * one composable DQL statement
 * `SELECT t, pt FROM <entityClass> pt JOIN pt.<farProperty> t WHERE pt.<parentProperty> = :parent`.
 *
 * @see PivotAssociationResolver auto-detects this (or honours the relation's `through()` override)
 */
final readonly class PivotAssociation
{
    /**
     * @param class-string $entityClass    the association (pivot) entity FQCN
     * @param string       $parentProperty the `ManyToOne` on the entity that points back to the parent
     * @param string       $farProperty    the `ManyToOne` on the entity that points at the far (related) type
     */
    public function __construct(
        public string $entityClass,
        public string $parentProperty,
        public string $farProperty,
    ) {}
}
