<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A user's library — the Doctrine-mapped twin of core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Domain/Library.php Library}.
 *
 * The polymorphic to-many (`MorphToMany items`) spans entity classes
 * (Track|Album|Artist), so the Doctrine reference provider **throws** on it — the
 * NET-NEW `LibraryItemsProvider` (built in the next phase) resolves the mixed
 * members across their per-type repositories. The resolved members are held on the
 * non-mapped {@see $items} property; `$owner` is the inverse side of the
 * user↔library OneToOne (the FK lives on {@see User}).
 *
 * The id is application-assigned. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'library')]
class Library
{
    /**
     * The inverse side of the user↔library OneToOne; the owning FK is on
     * {@see User::$library}.
     */
    #[ORM\OneToOne(targetEntity: User::class, mappedBy: 'library')]
    public ?User $owner = null;

    /**
     * The resolved mixed `items` (Track|Album|Artist), NOT a mapped association:
     * the `MorphToMany` members span entity classes, so the custom
     * `LibraryItemsProvider` resolves them across per-type repositories and the
     * resource's `MorphToMany` relation reads this list, rendering each member
     * through its own per-type serializer.
     *
     * @var list<object>
     */
    public array $items = [];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
    ) {}
}
