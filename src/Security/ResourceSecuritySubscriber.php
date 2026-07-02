<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

use haddowg\JsonApiBundle\Event\AfterFetchOneEvent;
use haddowg\JsonApiBundle\Event\BeforeActionEvent;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use haddowg\JsonApiBundle\Event\BeforeFetchCollectionEvent;
use haddowg\JsonApiBundle\Event\BeforeFetchRelatedEvent;
use haddowg\JsonApiBundle\Event\BeforeFetchRelationshipEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipMutateEvent;
use haddowg\JsonApiBundle\Event\BeforeUpdateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The built-in subscriber that enforces declarative resource authorization (bundle
 * ADR 0043). For each gated operation it resolves the type's declared
 * {@see ResourceSecurity} expression and evaluates it through Symfony's
 * {@see AuthorizationCheckerInterface} with the operation's subject as `object` — so
 * Symfony's `ExpressionVoter` supplies `user`/`object`/`request`/`token`/`roles` and
 * the `is_granted()` family — and **throws** {@see AccessDeniedException} (mapped to
 * a `403` JSON:API error by the route-scoped
 * {@see \haddowg\JsonApiBundle\EventListener\ExceptionListener}) when the expression
 * is false.
 *
 * It runs at the **before** hooks for writes — so a denial aborts before any
 * persist or side-effect — and at {@see AfterFetchOneEvent} for the single read. The
 * subject `$object` is:
 *  - {@see BeforeCreateEvent}             → the hydrated entity (post-denormalization);
 *  - {@see BeforeUpdateEvent}             → the loaded, changed entity;
 *  - {@see BeforeRelationshipMutateEvent} → the parent (gated by the relation's mutate
 *                                           expression, overriding the type's update);
 *  - {@see BeforeDeleteEvent}             → the loaded entity;
 *  - {@see AfterFetchOneEvent}            → the loaded entity;
 *  - {@see BeforeFetchRelatedEvent} / {@see BeforeFetchRelationshipEvent}
 *                                         → the parent (gated by the relation's own read
 *                                           expression, overriding the type's read; only
 *                                           dispatched when the relation declares one).
 *
 * A relation may thus be authorized **independently** of its parent: its declared
 * `security(read:, mutate:)` replaces the parent's gate for the relation's endpoints,
 * so it can be more *or* less permissive than the resource it hangs off (a relation
 * declaring nothing inherits the parent gate unchanged).
 *
 * Collection reads (`GET /{type}`) **are** gated here — all-or-nothing — by the
 * type's `securityList` declaration (bundle ADR 0099), evaluated at
 * {@see BeforeFetchCollectionEvent} **before** the query with a `null` `object` (a
 * collection has no single subject, so use a role/attribute check). Row-level read
 * authorization — hiding individual rows — belongs instead in a query-scope (a
 * Doctrine extension filtering the collection), not this single gate. A type that
 * declared no security is never gated
 * (the subscriber is a no-op for it), and the whole seam is inert when
 * `symfony/security-core` is absent (the subscriber is not registered). It is
 * likewise a no-op when no firewall is configured — `security.authorization_checker`
 * exists only with a firewall, so the checker is injected optionally and a declared
 * `security` stays inert until one is wired.
 */
final class ResourceSecuritySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ?AuthorizationCheckerInterface $authorizationChecker,
        private readonly ResourceSecurityRegistry $registry,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            BeforeCreateEvent::class => 'onBeforeCreate',
            BeforeUpdateEvent::class => 'onBeforeUpdate',
            BeforeRelationshipMutateEvent::class => 'onBeforeRelationshipMutate',
            BeforeDeleteEvent::class => 'onBeforeDelete',
            AfterFetchOneEvent::class => 'onAfterFetchOne',
            BeforeFetchCollectionEvent::class => 'onBeforeFetchCollection',
            BeforeFetchRelatedEvent::class => 'onBeforeFetchRelated',
            BeforeFetchRelationshipEvent::class => 'onBeforeFetchRelationship',
            BeforeActionEvent::class => 'onBeforeAction',
        ];
    }

    /**
     * Enforces the type's `securityList` declaration (the collection read,
     * `GET /{type}`) BEFORE the query runs, so a denied caller never triggers a
     * collection fetch. A collection has no single subject, so the expression is
     * evaluated with a `null` `object` — use a role/attribute check
     * (`is_granted('ROLE_ADMIN')`), not a per-object one. Only a string expression is
     * enforced; a bool (`true`/`false`) is a documentation-only declaration. `null`
     * (after the `security` fallback) leaves the collection ungated by this layer.
     */
    public function onBeforeFetchCollection(BeforeFetchCollectionEvent $event): void
    {
        $expression = $this->registry->securityFor($event->type)?->forList();
        if (!\is_string($expression) || $this->authorizationChecker === null) {
            return;
        }

        if (!$this->authorizationChecker->isGranted(new Expression($expression), null)) {
            throw new AccessDeniedException(\sprintf(
                'Access denied to the collection of the JSON:API type "%s".',
                $event->type,
            ));
        }
    }

    /**
     * Enforces a custom action's per-action {@see BeforeActionEvent::$security}
     * expression (bundle ADR 0076) — carried on the *event*, not the
     * {@see ResourceSecurityRegistry} (it is per-action, not per-type) — evaluating
     * it against the action's subject: the resolved entity for a resource-scope
     * action, `null` for a collection-scope action. A `null` expression leaves the
     * action ungated; a false result throws {@see AccessDeniedException} (→ `403`),
     * exactly mirroring the create/update before-gates.
     */
    public function onBeforeAction(BeforeActionEvent $event): void
    {
        if ($event->security === null || $this->authorizationChecker === null) {
            return;
        }

        if (!$this->authorizationChecker->isGranted(new Expression($event->security), $event->subject)) {
            throw new AccessDeniedException(\sprintf(
                'Access denied to the JSON:API action "%s" on the type "%s".',
                $event->action,
                $event->type,
            ));
        }
    }

    public function onBeforeCreate(BeforeCreateEvent $event): void
    {
        $this->authorize($event->type, $this->registry->securityFor($event->type)?->forCreate(), $event->entity);
    }

    public function onBeforeUpdate(BeforeUpdateEvent $event): void
    {
        $this->authorize($event->type, $this->registry->securityFor($event->type)?->forUpdate(), $event->entity);
    }

    public function onBeforeRelationshipMutate(BeforeRelationshipMutateEvent $event): void
    {
        // The relation's own mutate security OVERRIDES the parent's update gate (it may
        // be more *or* less permissive); a relation declaring none falls back to the
        // parent's update expression. The subject is the parent resource in both cases.
        $expression = $event->relation->securityMutate() ?? $this->registry->securityFor($event->type)?->forUpdate();
        $this->authorize($event->type, $expression, $event->parent);
    }

    public function onBeforeDelete(BeforeDeleteEvent $event): void
    {
        $this->authorize($event->type, $this->registry->securityFor($event->type)?->forDelete(), $event->entity);
    }

    public function onAfterFetchOne(AfterFetchOneEvent $event): void
    {
        $this->authorize($event->type, $this->registry->securityFor($event->type)?->forRead(), $event->entity);
    }

    /**
     * Enforces a relation's own read security on the related read
     * (`GET /{type}/{id}/{rel}`). The handler dispatches this event only when the
     * relation declares {@see \haddowg\JsonApi\Resource\Field\RelationInterface::securityRead()},
     * which OVERRIDES the parent's read gate; the subject is the parent resource. (A
     * relation declaring no read security keeps the parent's {@see AfterFetchOneEvent}
     * gate, so this is never dispatched for it.)
     */
    public function onBeforeFetchRelated(BeforeFetchRelatedEvent $event): void
    {
        $this->authorize($event->type, $event->relation->securityRead(), $event->parent);
    }

    /**
     * The relationship-linkage twin of {@see onBeforeFetchRelated()}: enforces the
     * relation's own read security on `GET /{type}/{id}/relationships/{rel}`.
     */
    public function onBeforeFetchRelationship(BeforeFetchRelationshipEvent $event): void
    {
        $this->authorize($event->type, $event->relation->securityRead(), $event->parent);
    }

    /**
     * Evaluates `$expression` (when the operation is gated by a string expression)
     * against `$subject`, throwing {@see AccessDeniedException} when it is false. A
     * `null` leaves the operation ungated; a **bool** is a documentation-only
     * declaration (`true` = an external firewall enforces it, `false` = public) and is
     * never enforced here.
     */
    private function authorize(string $type, string|bool|null $expression, object $subject): void
    {
        if (!\is_string($expression) || $this->authorizationChecker === null) {
            return;
        }

        if (!$this->authorizationChecker->isGranted(new Expression($expression), $subject)) {
            throw new AccessDeniedException(\sprintf(
                'Access denied to %s on the JSON:API type "%s".',
                $subject::class,
                $type,
            ));
        }
    }
}
