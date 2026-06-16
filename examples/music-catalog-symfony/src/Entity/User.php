<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user — the Doctrine-mapped twin of core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Domain/User.php User}.
 *
 * `email` is unique (the resource attaches a `UniqueEntity` rule, which queries
 * this repository through `symfony/doctrine-bridge`); `preferences` is a JSON
 * column behind an `ArrayHash`; `password`/`passwordConfirm` are write-only on the
 * resource. The `library` OneToOne is the owning side carrying the FK.
 *
 * The id is application-assigned. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User
{
    /**
     * The inverse side of the playlist→owner association: a user's playlists,
     * mapped by {@see Playlist}'s owning `owner` reference.
     *
     * @var Collection<int, Playlist>
     */
    #[ORM\OneToMany(targetEntity: Playlist::class, mappedBy: 'owner')]
    public Collection $playlists;

    /**
     * @param array<string, mixed> $preferences
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column(unique: true)]
        public string $email = '',
        #[ORM\Column(name: 'display_name')]
        public string $displayName = '',
        #[ORM\Column(name: 'birth_date', type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $birthDate = null,
        #[ORM\Column(type: 'json')]
        public array $preferences = [],
        #[ORM\Column(name: 'last_seen_ip', nullable: true)]
        public ?string $lastSeenIp = null,
        #[ORM\Column(nullable: true)]
        public ?string $password = null,
        // The owning side of the user↔library OneToOne (the FK lives here).
        #[ORM\OneToOne(targetEntity: Library::class, inversedBy: 'owner')]
        #[ORM\JoinColumn(name: 'library_id', referencedColumnName: 'id', nullable: true)]
        public ?Library $library = null,
    ) {
        $this->playlists = new ArrayCollection();
    }
}
