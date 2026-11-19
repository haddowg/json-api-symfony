<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\AdmitsRowsInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\TagEntity;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Serves secret tags to admins only, and says which rows it admits in one probe.
 *
 * @extends AbstractDataProvider<object>
 */
final class PublicTagsProvider extends AbstractDataProvider implements AdmitsRowsInterface
{
    public function __construct(private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'tags';
    }

    public function scopesRelatedReads(RelationInterface $relation): bool
    {
        return true;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach ($this->visible(ThroughProviderFactory::slice('tags')) as $tag) {
            if ((string) $tag->id === $id) {
                return $tag;
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider('tags', $this->visible(ThroughProviderFactory::slice('tags'))))->fetchCollection('tags', $criteria);
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        $held = \property_exists($parent, 'tags') && \is_iterable($parent->tags) ? $parent->tags : [];

        return (new InMemoryDataProvider('tags', $this->visible($held)))->fetchCollection('tags', $criteria);
    }

    public function admitRows(string $type, array $rows, JsonApiRequestInterface $request): array
    {
        return $this->visible($rows);
    }

    /**
     * @param iterable<mixed> $tags
     *
     * @return list<TagEntity>
     */
    private function visible(iterable $tags): array
    {
        $admin = $this->security->isGranted('ROLE_ADMIN');
        $visible = [];
        foreach ($tags as $tag) {
            if ($tag instanceof TagEntity && ($admin || !$tag->secret)) {
                $visible[] = $tag;
            }
        }

        return $visible;
    }
}
