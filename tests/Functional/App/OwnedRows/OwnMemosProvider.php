<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OwnedRows;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Serves `memos`, which aren't on Doctrine, and only ever the signed-in user's: the
 * custom scoped provider example in the authorization guide.
 *
 * @extends AbstractDataProvider<object>
 */
final class OwnMemosProvider extends AbstractDataProvider
{
    public function __construct(private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'memos';
    }

    public function scopesRelatedReads(RelationInterface $relation): bool
    {
        return true;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach ($this->own(Memo::all()) as $memo) {
            if ((string) $memo->id === $id) {
                return $memo;
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->query($this->own(Memo::all()), $criteria);
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        return $this->query($this->own(Memo::heldBy($parent)), $criteria);
    }

    /**
     * Scope first, so filters, sorting, pages and counts all run over the user's memos.
     *
     * @param list<Memo> $memos
     *
     * @return CollectionResult<object>
     */
    private function query(array $memos, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider('memos', $memos))->fetchCollection('memos', $criteria);
    }

    /**
     * @param iterable<mixed> $memos
     *
     * @return list<Memo>
     */
    private function own(iterable $memos): array
    {
        $user = $this->security->getUser()?->getUserIdentifier();
        $own = [];
        foreach ($memos as $memo) {
            if ($memo instanceof Memo && $user !== null && $memo->owner === $user) {
                $own[] = $memo;
            }
        }

        return $own;
    }
}
