<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * An After hook that, asked by the `X-Note` header, answers a card collection with its
 * own meta: the note no hook supplies and an `open` count that wins over the extracted
 * one. Asked by `X-Bogus-Meta`, it adds a member the declaration does not name, and by
 * `X-Wrong-Type`, it sets `open` to a string.
 */
final class TmMetaSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ServerProvider $servers) {}

    public static function getSubscribedEvents(): array
    {
        return [AfterFetchCollectionEvent::class => 'onCollection'];
    }

    public function onCollection(AfterFetchCollectionEvent $event): void
    {
        if ($event->type !== 'tm-cards') {
            return;
        }

        $meta = match (true) {
            $event->request->hasHeader('X-Note') => ['note' => $event->request->getHeaderLine('X-Note'), 'open' => 99],
            $event->request->hasHeader('X-Bogus-Meta') => ['bogus' => true],
            $event->request->hasHeader('X-Wrong-Type') => ['open' => 'two'],
            default => null,
        };
        if ($meta === null) {
            return;
        }

        $event->setResponse(DataResponse::fromCollection($event->items, $this->servers->get()->serializerFor('tm-cards'))->withMeta($meta));
    }
}
