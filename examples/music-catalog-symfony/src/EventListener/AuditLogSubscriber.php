<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\EventListener;

use haddowg\JsonApiBundle\Event\AfterDeleteEvent;
use haddowg\JsonApiBundle\Event\AfterSaveEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use haddowg\JsonApiBundle\Event\ServingEvent;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Hook\AuditLog;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Hook\HookAbortException;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The **cross-cutting event subscriber** — the example's witness for the lifecycle
 * seam's *event* mechanism (the twin of the per-type resource-method hooks on
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\PlaylistResource}).
 * Being a plain `EventSubscriberInterface`, it is autoconfigured by the example's
 * `services.yaml` — no bundle wiring — and listens to events fired for **every**
 * type, so one concern (here: an audit trail + a read-only gate) spans the whole
 * API without touching any resource.
 *
 * Two hooks:
 *  - a **`serving`** gate (fired once per request, before the operation): when the
 *    request carries an `X-Read-Only: on` header it aborts with a `403`, so a
 *    deploy flag can freeze every write across the API in one place. A `serving`
 *    throw aborts before the operation runs — no entity is loaded, nothing commits;
 *  - an **after-commit** audit record on {@see AfterSaveEvent} (every create AND
 *    update — `$creating` distinguishes them) and {@see AfterDeleteEvent}, appended
 *    to the public {@see AuditLog}. After hooks fire post-commit, so an entry means
 *    the write durably happened. The wire id is captured in {@see onBeforeDelete()}
 *    (the entity is still live there) because a deleted entity's id is no longer
 *    readable once the flush has run.
 *
 * The {@see ServerProvider} resolves the type's serializer — on the server the
 * operation dispatched on — to read the committed entity's wire id for the audit
 * line, so an admin-server-only type (`users`) audits correctly under multi-server.
 */
final class AuditLogSubscriber implements EventSubscriberInterface
{
    /** The wire id captured before a delete, so the post-commit line can name it. */
    private ?string $deletingId = null;

    public function __construct(
        private readonly AuditLog $audit,
        private readonly ServerProvider $servers,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ServingEvent::class => 'onServing',
            AfterSaveEvent::class => 'onAfterSave',
            BeforeDeleteEvent::class => 'onBeforeDelete',
            AfterDeleteEvent::class => 'onAfterDelete',
        ];
    }

    public function onServing(ServingEvent $event): void
    {
        // A serving subscriber sees the raw request: a `403` here aborts before any
        // operation runs (the route-scoped ExceptionListener renders the thrown
        // status). The PSR-7 header read works because JsonApiRequestInterface is a
        // ServerRequestInterface.
        if (\strtolower($event->request->getHeaderLine('X-Read-Only')) === 'on'
            && !\in_array($event->request->getMethod(), ['GET', 'HEAD'], true)
        ) {
            throw HookAbortException::forbidden('The API is in read-only mode.');
        }
    }

    public function onAfterSave(AfterSaveEvent $event): void
    {
        $this->audit->record(
            $event->creating ? 'created' : 'updated',
            $event->type,
            $this->idOf($event->serverName, $event->type, $event->entity),
        );
    }

    public function onBeforeDelete(BeforeDeleteEvent $event): void
    {
        // The entity is still live here; its id is no longer readable after the flush.
        $this->deletingId = $this->idOf($event->serverName, $event->type, $event->entity);
    }

    public function onAfterDelete(AfterDeleteEvent $event): void
    {
        $this->audit->record('deleted', $event->type, $this->deletingId ?? '');
        $this->deletingId = null;
    }

    /**
     * Resolves the wire id off the **server the operation dispatched on** (every
     * event carries its `$serverName`): a type exposed only on a named server — the
     * admin-only `users` here — is not registered on the `default` server, so a
     * multi-server-aware audit resolves the serializer on the right one.
     */
    private function idOf(string $serverName, string $type, object $entity): string
    {
        return $this->servers->get($serverName)->serializerFor($type)->getId($entity);
    }
}
