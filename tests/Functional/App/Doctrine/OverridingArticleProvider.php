<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Article;

/**
 * An application-style provider for `articles` registered alongside the
 * Doctrine fallback: plain autoconfiguration, no explicit tag priority — the
 * exact shape a user writes to take over one type. It serves a fixture set the
 * database never contains, so any read it answers is attributable to it.
 *
 * It delegates to an {@see InMemoryDataProvider}, so it takes that provider's
 * object-typed reads (like the reference Doctrine provider, which is also
 * `DataProviderInterface<object>`).
 *
 * @implements DataProviderInterface<object>
 */
final class OverridingArticleProvider implements DataProviderInterface
{
    public const string TITLE = 'From the override provider';

    private readonly InMemoryDataProvider $inner;

    public function __construct()
    {
        $this->inner = new InMemoryDataProvider('articles', [
            '1' => new Article(1, self::TITLE, 'Not a database row.'),
        ]);
    }

    public function supports(string $type): bool
    {
        return $type === 'articles';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->inner->fetchOne($type, $id);
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->inner->fetchCollection($type, $criteria);
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        return $this->inner->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
    }

    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        return $this->inner->fetchRelationshipPivot($type, $parent, $relation);
    }

    public function countRelated(
        string $type,
        array $parents,
        RelationInterface $relation,
        JsonApiRequestInterface $request,
    ): array {
        return $this->inner->countRelated($type, $parents, $relation, $request);
    }
}
