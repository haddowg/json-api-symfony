<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Event\AfterFetchOneEvent;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Answers a read of a binding with its template, a resource the read itself never
 * loaded relations for.
 */
final class AnswerWithTemplateSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ServerProvider $servers) {}

    public static function getSubscribedEvents(): array
    {
        return [AfterFetchOneEvent::class => 'onFetchOne'];
    }

    public function onFetchOne(AfterFetchOneEvent $event): void
    {
        $binding = $event->entity;
        if ($event->type !== 'bindings' || !$binding instanceof LinkageBinding) {
            return;
        }

        $event->setResponse(DataResponse::fromResource($binding->template, $this->servers->get($event->serverName)->serializerFor('templates')));
    }
}
