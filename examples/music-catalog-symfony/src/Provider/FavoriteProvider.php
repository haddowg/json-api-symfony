<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Provider;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Artist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Favorite;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;

/**
 * The polymorphic **to-one** resolver (seam 3) — the first Doctrine functional
 * witness of a `MorphTo` read. A {@see Favorite} stores its polymorphic target as
 * a plain `targetType`/`targetId` pair (no FK, since the target spans entity
 * classes), so the generic Doctrine read leaves the non-mapped `$favoritable`
 * property null. This provider delegates the fetch to the Doctrine provider, then
 * **resolves the target across per-type repositories** onto `$favoritable` — the
 * object the resource's `MorphTo` relation reads, resolving its serializer from
 * the related object's own type (a track, album, or artist). A favorite with no
 * target renders `data: null`.
 *
 * It shares the {@see EntityManagerInterface} with the Doctrine provider, so the
 * resolved member is managed. Registered for `favorites` at default priority, it
 * shadows the `-128` Doctrine fallback for that type alone.
 *
 * @implements DataProviderInterface<object>
 */
final class FavoriteProvider implements DataProviderInterface
{
    /**
     * @var array<string, class-string>
     */
    private const array ENTITY_CLASS_BY_TYPE = [
        'tracks' => Track::class,
        'albums' => Album::class,
        'artists' => Artist::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DoctrineDataProvider $doctrine,
    ) {}

    public function supports(string $type): bool
    {
        return $type === 'favorites';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $favorite = $this->doctrine->fetchOne($type, $id);
        if ($favorite instanceof Favorite) {
            $this->resolveTarget($favorite);
        }

        return $favorite;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $result = $this->doctrine->fetchCollection($type, $criteria);

        foreach ($result->items as $favorite) {
            if ($favorite instanceof Favorite) {
                $this->resolveTarget($favorite);
            }
        }

        return $result;
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        return $this->doctrine->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
    }

    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        return $this->doctrine->fetchRelationshipPivot($type, $parent, $relation);
    }

    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): array {
        return $this->doctrine->countRelated($type, $parents, $relation, $request);
    }

    /**
     * Fills the non-mapped `$favoritable` from the stored `targetType`/`targetId`
     * pair, looking the member up in its per-type repository (so it comes back
     * managed). An absent or unrecognised pair leaves it null → `data: null`.
     */
    private function resolveTarget(Favorite $favorite): void
    {
        if ($favorite->targetType === null || $favorite->targetId === null) {
            return;
        }

        $entityClass = self::ENTITY_CLASS_BY_TYPE[$favorite->targetType] ?? null;
        if ($entityClass === null) {
            return;
        }

        $favorite->favoritable = $this->entityManager->getRepository($entityClass)->find($favorite->targetId);
    }
}
