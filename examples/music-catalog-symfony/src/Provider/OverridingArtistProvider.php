<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Provider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Artist;

/**
 * The priority-shadow witness (seam 2, the precedence half): a default-priority
 * provider for `artists` registered alongside the bundled Doctrine fallback.
 * Plain autoconfiguration tags it at priority `0`, so it sorts ahead of the
 * Doctrine provider (registered at `-128`) and **shadows** it for `artists` with
 * no configuration at all — the exact shape a user writes to take over one type.
 *
 * Re-themed from the bundle's own
 * {@see https://github.com/haddowg/json-api-symfony OverridingArticleProvider}.
 * Because the example app is one shared kernel over a seeded database, the
 * override is a thin **overlay** rather than a wholesale replacement: it answers a
 * single sentinel id (`999999`, an id far above any seeded auto-increment row — so a
 * read of it is attributable to the override alone, which {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Tests\CustomProviderTest}
 * asserts) and delegates every other `artists` read to the injected Doctrine
 * provider, so the real `/artists` endpoint stays intact for the rest of the app.
 * Both the override and the Doctrine fallback are wired (the resource maps an
 * entity); the override wins by priority, not by the fallback's absence.
 *
 * @implements DataProviderInterface<object>
 */
final class OverridingArtistProvider implements DataProviderInterface
{
    public const string SENTINEL_ID = '999999';

    public const string NAME = 'From the override provider';

    public function __construct(private readonly DoctrineDataProvider $doctrine) {}

    public function supports(string $type): bool
    {
        return $type === 'artists';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        if ($id === self::SENTINEL_ID) {
            // A synthetic artist the database never holds: the int PK is set directly
            // (it is never persisted), so the resource renders the sentinel wire id.
            $artist = new Artist(name: self::NAME, slug: 'override', trackCount: 0);
            $artist->id = (int) self::SENTINEL_ID;

            return $artist;
        }

        return $this->doctrine->fetchOne($type, $id);
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->doctrine->fetchCollection($type, $criteria);
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
}
