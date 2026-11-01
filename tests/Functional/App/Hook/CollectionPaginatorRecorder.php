<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApiBundle\Event\AfterFetchCollectionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records the paginator each {@see AfterFetchCollectionEvent} carries, so the
 * paginator suite can assert what a subscriber sees.
 */
final class CollectionPaginatorRecorder implements EventSubscriberInterface
{
    /** @var list<PaginatorInterface|null> */
    public static array $seen = [];

    public static function getSubscribedEvents(): array
    {
        return [AfterFetchCollectionEvent::class => 'onAfterFetchCollection'];
    }

    public function onAfterFetchCollection(AfterFetchCollectionEvent $event): void
    {
        self::$seen[] = $event->paginator;
    }
}
