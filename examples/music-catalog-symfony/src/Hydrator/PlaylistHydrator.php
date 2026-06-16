<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Hydrator;

use haddowg\JsonApi\Hydrator\AbstractHydrator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Playlist;

/**
 * A hand-written hydrator for the `playlists` type — the hydrator-override escape
 * hatch, registered on {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\PlaylistResource}
 * via `#[AsJsonApiResource(hydrator: PlaylistHydrator::class)]` (bundle ADR 0023).
 * It wins for writes while the resource still serializes reads.
 *
 * Re-themed from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Hydrator/PlaylistHydrator.php PlaylistHydrator},
 * with one Symfony-specific addition: it takes a **bound constructor argument**
 * (`$slugSeparator`, the slug delimiter), so a successful write proves the bundle
 * resolved it through the container *with* its dependency. Extending
 * {@see AbstractHydrator} gives the create/update dispatch, the relationship
 * write path, and the {@see validateDomainObject()} seam; this class declares only
 * the attribute fan-out (`title` → `title` + derived read-only `slug`), the
 * client-generated-id policy, and the post-hydration business rule.
 */
final class PlaylistHydrator extends AbstractHydrator
{
    public function __construct(private readonly string $slugSeparator) {}

    protected function getAcceptedTypes(): array
    {
        return ['playlists'];
    }

    protected function getAttributeHydrator(mixed $domainObject): array
    {
        $separator = $this->slugSeparator;

        return [
            // The fan-out: one `title` member fills the title AND derives the
            // read-only `slug`. A field-DSL resource cannot express "set one column
            // from another" — this is why you hand-write a hydrator.
            'title' => static function (mixed $playlist, mixed $value, array $data, string $field) use ($separator): Playlist {
                \assert($playlist instanceof Playlist);
                $title = \is_string($value) ? \trim($value) : '';
                $playlist->title = $title;
                $playlist->slug = self::slugify($title, $separator);

                return $playlist;
            },
            'public' => static function (mixed $playlist, mixed $value, array $data, string $field): Playlist {
                \assert($playlist instanceof Playlist);
                $playlist->public = (bool) $value;

                return $playlist;
            },
            'externalId' => static function (mixed $playlist, mixed $value, array $data, string $field): Playlist {
                \assert($playlist instanceof Playlist);
                $playlist->externalId = \is_string($value) && $value !== '' ? $value : null;

                return $playlist;
            },
        ];
    }

    protected function getRelationshipHydrator(mixed $domainObject): array
    {
        // No relationship hydration here: the handler owns it (resolving a linkage id
        // to a stored/managed related object needs storage the hydrator cannot reach).
        return [];
    }

    /**
     * This custom hydrator accepts a client-supplied UUID (the resource also declares
     * `uuid()` so the wire format is validated before this runs); when a create omits
     * the id, {@see generateId()} mints one.
     */
    protected function validateClientGeneratedId(string $clientGeneratedId, JsonApiRequestInterface $request): void
    {
        // Accepted: no-op.
    }

    protected function validateRequest(JsonApiRequestInterface $request): void
    {
        // No request-level pre-checks for playlists.
    }

    protected function generateId(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($bytes), 4));
    }

    protected function setId(mixed $domainObject, string $id): mixed
    {
        \assert($domainObject instanceof Playlist);
        $domainObject->id = $id;

        return $domainObject;
    }

    /**
     * The post-hydration seam: a cross-field business rule checked once the object
     * is fully built. A title is mandatory, so a derived slug must be non-empty.
     */
    protected function validateDomainObject(JsonApiRequestInterface $request, mixed $domainObject): void
    {
        \assert($domainObject instanceof Playlist);

        if ($domainObject->title !== '' && $domainObject->slug === '') {
            throw new \LogicException('A titled playlist must have a derived slug.');
        }
    }

    /**
     * Lower-cases, strips non-alphanumerics to the bound separator, and trims — a
     * minimal slugifier (not production-grade transliteration).
     */
    private static function slugify(string $title, string $separator): string
    {
        $slug = \strtolower($title);
        $slug = \preg_replace('/[^a-z0-9]+/', $separator, $slug) ?? '';

        return \trim($slug, $separator);
    }
}
