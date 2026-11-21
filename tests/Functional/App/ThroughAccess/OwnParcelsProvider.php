<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\ParcelEntity;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Serves each caller only their own parcels, an admin all of them. It doesn't say which
 * rows it admits in one probe, so a walk through it asks {@see fetchOne()} per row.
 *
 * @extends AbstractDataProvider<object>
 */
final class OwnParcelsProvider extends AbstractDataProvider
{
    public function __construct(private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'parcels';
    }

    public function scopesRelatedReads(RelationInterface $relation): bool
    {
        return true;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach ($this->own(ThroughProviderFactory::slice('parcels')) as $parcel) {
            if ((string) $parcel->id === $id) {
                return $parcel;
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->query($this->own(ThroughProviderFactory::slice('parcels')), $criteria);
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        $held = \property_exists($parent, 'parcels') && \is_iterable($parent->parcels) ? $parent->parcels : [];

        return $this->query($this->own($held), $criteria);
    }

    /**
     * @param list<ParcelEntity> $parcels
     *
     * @return CollectionResult<object>
     */
    private function query(array $parcels, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider('parcels', $parcels))->fetchCollection('parcels', $criteria);
    }

    /**
     * @param iterable<mixed> $parcels
     *
     * @return list<ParcelEntity>
     */
    private function own(iterable $parcels): array
    {
        $admin = $this->security->isGranted('ROLE_ADMIN');
        $user = $this->security->getUser()?->getUserIdentifier();
        $own = [];
        foreach ($parcels as $parcel) {
            if ($parcel instanceof ParcelEntity && ($admin || $parcel->owner === $user)) {
                $own[] = $parcel;
            }
        }

        return $own;
    }
}
