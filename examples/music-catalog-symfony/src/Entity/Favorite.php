<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A favorite — the Doctrine-mapped twin of core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Domain/Favorite.php Favorite}.
 *
 * The polymorphic to-one (`MorphTo favoritable`) cannot be a single ORM
 * association because its target spans entity classes (Track|Album|Artist), so it
 * is stored as a **`targetType` + `targetId` pair** of plain columns. The resolved
 * related object is held on the non-mapped {@see $favoritable} property — the
 * Doctrine provider hydrates it from the pair on read (the first Doctrine witness
 * of a polymorphic to-one; built in the next phase), and the resource's `MorphTo`
 * relation reads it off the entity. `$user` is an ordinary ManyToOne.
 *
 * The id is application-assigned. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'favorite')]
class Favorite
{
    /**
     * The resolved `favoritable` related object (Track|Album|Artist), NOT a mapped
     * column: it is populated from the {@see $targetType}/{@see $targetId} pair by
     * the provider, and read by the resource's `MorphTo` relation, which resolves
     * the serializer from the object's own type.
     */
    public ?object $favoritable = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column(name: 'favorited_at', type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $favoritedAt = null,
        // The polymorphic pointer: the JSON:API type and id of the favoritable
        // member, stored as plain columns (no FK, since the target spans classes).
        #[ORM\Column(name: 'target_type', nullable: true)]
        public ?string $targetType = null,
        #[ORM\Column(name: 'target_id', nullable: true)]
        public ?string $targetId = null,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
        public ?User $user = null,
    ) {}
}
