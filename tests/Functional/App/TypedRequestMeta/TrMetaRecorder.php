<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApiBundle\Event\AfterCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\BeforeUpdateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records the typed meta each write's lifecycle events carry.
 */
final class TrMetaRecorder implements EventSubscriberInterface
{
    /**
     * @var array<string, array{document: array<string, mixed>, resource: array<string, mixed>}>
     */
    public static array $seen = [];

    /**
     * The top-level meta each event's request carries as sent.
     *
     * @var array<string, mixed>
     */
    public static array $raw = [];

    /**
     * The resource object meta each event's request carries as sent.
     *
     * @var array<string, mixed>
     */
    public static array $rawResource = [];

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeCreateEvent::class => 'record',
            AfterCreateEvent::class => 'record',
            BeforeUpdateEvent::class => 'record',
            BeforeDeleteEvent::class => 'record',
            BeforeRelationshipMutateEvent::class => 'record',
        ];
    }

    public function record(BeforeCreateEvent|AfterCreateEvent|BeforeUpdateEvent|BeforeDeleteEvent|BeforeRelationshipMutateEvent $event): void
    {
        $meta = $event->writtenMeta();
        $name = (new \ReflectionClass($event))->getShortName();
        self::$seen[$event->type . ':' . $name] = ['document' => $meta->document, 'resource' => $meta->resource];
        $body = $event->request->getParsedBody();
        self::$raw[$event->type . ':' . $name] = \is_array($body) ? ($body['meta'] ?? null) : null;
        $data = \is_array($body) ? ($body['data'] ?? null) : null;
        self::$rawResource[$event->type . ':' . $name] = \is_array($data) ? ($data['meta'] ?? null) : null;
    }
}
