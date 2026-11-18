<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;

/**
 * Serves `fk-users` and the `fk-inboxes` singleton from a fixed list, keeping
 * {@see AbstractDataProvider}'s defaults for every related read: the users hold none of
 * their related rows.
 *
 * @extends AbstractDataProvider<FkUser>
 */
final class FkUserProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === 'fk-users' || $type === 'fk-inboxes';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach (self::users() as $user) {
            if ($user->id === $id) {
                return $user;
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return new CollectionResult(self::users());
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
