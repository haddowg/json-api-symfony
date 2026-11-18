<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;

/**
 * Serves `fk-users` and the `fk-inboxes` singleton from a fixed list, keeping
 * {@see AbstractDataProvider}'s defaults for every related read. Ada's pinned items, 5, 4
 * and 3 in that order, and her favourite, item 2, are loaded onto her model from the
 * items' own store.
 *
 * @extends AbstractDataProvider<FkUser>
 */
final class FkUserProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === 'fk-users' || $type === 'fk-inboxes';
    }

    public function __construct(private readonly ?EntityManagerInterface $entityManager = null) {}

    public function fetchOne(string $type, string $id): ?object
    {
        foreach (self::users() as $user) {
            if ($user->id === $id) {
                return $this->withItems($user);
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return new CollectionResult(\array_map($this->withItems(...), self::users()));
    }

    private function withItems(FkUser $user): FkUser
    {
        if ($user->id !== 'ada') {
            return $user;
        }

        $user->pinned = \array_values(\array_filter(\array_map($this->item(...), [5, 4, 3])));
        $user->favourite = $this->item(2);

        return $user;
    }

    private function item(int $id): ?FkItem
    {
        $item = $this->entityManager !== null
            ? $this->entityManager->find(FkItem::class, $id)
            : ForeignKeyInMemoryTestKernel::provider('fk-items')->fetchOne('fk-items', (string) $id);

        return $item instanceof FkItem ? $item : null;
    }

    /**
     * @return list<FkUser>
     */
    private static function users(): array
    {
        static $users = null;

        return $users ??= [new FkUser('ada', 'Ada'), new FkUser('bob', 'Bob'), new FkUser('cy', 'Cy')];
    }
}
