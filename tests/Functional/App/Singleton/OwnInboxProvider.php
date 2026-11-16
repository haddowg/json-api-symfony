<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The `inboxes` access scope in memory: every read, including a relation reaching the type,
 * sees only the caller's inbox.
 *
 * @extends AbstractDataProvider<object>
 */
final class OwnInboxProvider extends AbstractDataProvider
{
    public function __construct(private readonly InMemoryDataProvider $inner, private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'inboxes';
    }

    public function scopesRelatedReads(RelationInterface $relation): bool
    {
        return true;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $inbox = $this->inner->fetchOne($type, $id);

        return $inbox !== null && $this->owns($inbox) ? $inbox : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $own = [];
        foreach ($this->inner->store()->all() as $inbox) {
            if ($this->owns($inbox)) {
                $own[] = $inbox;
            }
        }

        return (new InMemoryDataProvider('inboxes', $own))->fetchCollection('inboxes', $criteria);
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        $inbox = $parent instanceof InboxItem ? $parent->inbox : null;
        $own = $inbox !== null && $this->owns($inbox) ? [$inbox] : [];

        return (new InMemoryDataProvider('inboxes', $own))->fetchCollection('inboxes', $criteria);
    }

    public function relatedToOneMatches(string $relatedType, object $related, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): bool
    {
        return $this->owns($related);
    }

    private function owns(object $inbox): bool
    {
        $user = $this->security->getUser()?->getUserIdentifier();

        return $inbox instanceof Inbox && $user !== null && $inbox->owner === $user;
    }
}
