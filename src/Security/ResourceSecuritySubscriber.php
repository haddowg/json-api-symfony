<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

use haddowg\JsonApiBundle\Event\AfterFetchOneEvent;
use haddowg\JsonApiBundle\Event\BeforeActionEvent;
use haddowg\JsonApiBundle\Event\BeforeApplyUpdateEvent;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
use haddowg\JsonApiBundle\Event\BeforeEmbeddedRelationshipWriteEvent;
use haddowg\JsonApiBundle\Event\BeforeFetchCollectionEvent;
use haddowg\JsonApiBundle\Event\BeforeFetchRelatedEvent;
use haddowg\JsonApiBundle\Event\BeforeFetchRelationshipEvent;
use haddowg\JsonApiBundle\Event\BeforeMemberMoveEvent;
use haddowg\JsonApiBundle\Event\BeforeRelationshipCommitEvent;
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
 *  - {@see BeforeApplyUpdateEvent}        → the loaded entity, before the body is applied;
 *  - {@see BeforeUpdateEvent}             → the loaded, changed entity;
 *  - {@see BeforeRelationshipMutateEvent} → the parent (gated by the relation's mutate
 *                                           expression, overriding the type's update);
 *  - {@see BeforeEmbeddedRelationshipWriteEvent}
 *                                         → the parent (gated by the relation's own mutate
 *                                           expression only; the parent's create/update
 *                                           gate covers the rest of the write);
 *  - {@see BeforeMemberMoveEvent}         → the member's previous parent, else the
 *                                           member itself (see {@see onBeforeMemberMove()});
 *  - {@see BeforeDeleteEvent}             → the loaded entity;
 *  - {@see AfterFetchOneEvent}            → the loaded entity;
 *  - {@see BeforeFetchRelatedEvent} / {@see BeforeFetchRelationshipEvent}
 *                                         → the parent (gated by the relation's own read
 *                                           expression, overriding the type's read; only
 *                                           dispatched when the relation declares one).
 *
 * An update is gated twice by the same expression: against the stored entity, so a
 * body cannot make the caller pass by rewriting what the expression reads (an owner),
 * and against the changed entity, so a caller cannot turn the resource into one they
 * could not edit (handing it to someone else). A relationship mutation is gated
 * against the parent before the persister applies it.
 *
 * A relation may be authorized **independently** of its parent: its declared
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
 * declared no security is never gated (the subscriber is a no-op for it).
 *
 * The authorization checker is injected optionally so an app that declares no
 * expression needs no firewall. The build already fails when an expression is declared
 * without one (bundle ADR 0130); if the subscriber still meets an expression it cannot
 * evaluate, it throws rather than let the request through.
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
            BeforeApplyUpdateEvent::class => 'onBeforeApplyUpdate',
            BeforeUpdateEvent::class => 'onBeforeUpdate',
            BeforeRelationshipMutateEvent::class => 'onBeforeRelationshipMutate',
            BeforeRelationshipCommitEvent::class => 'onBeforeRelationshipCommit',
            BeforeEmbeddedRelationshipWriteEvent::class => 'onBeforeEmbeddedRelationshipWrite',
            BeforeMemberMoveEvent::class => 'onBeforeMemberMove',
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
        if (!\is_string($expression)) {
            return;
        }

        if (!$this->isGranted($expression, null)) {
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
        if ($event->security === null) {
            return;
        }

        if (!$this->isGranted($event->security, $event->subject)) {
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

    public function onBeforeApplyUpdate(BeforeApplyUpdateEvent $event): void
    {
        $this->authorize($event->type, $this->registry->securityFor($event->type)?->forUpdate(), $event->entity);
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

    /**
     * Checks the same expression as {@see onBeforeRelationshipMutate()} against the parent
     * after the change is applied and before it commits, so a caller can't change a
     * resource into one the expression would refuse them, such as handing it to another
     * user (bundle ADR 0136).
     */
    public function onBeforeRelationshipCommit(BeforeRelationshipCommitEvent $event): void
    {
        $expression = $event->relation->securityMutate() ?? $this->registry->securityFor($event->type)?->forUpdate();
        $this->authorize($event->type, $expression, $event->parent);
    }

    /**
     * A relationship set in a create or update body is gated by the relation's own
     * mutate expression, as its relationship endpoint is. A relation declaring none adds
     * no gate: the write is already gated by the type's create or update expression.
     */
    public function onBeforeEmbeddedRelationshipWrite(BeforeEmbeddedRelationshipWriteEvent $event): void
    {
        $this->authorize($event->type, $event->relation->securityMutate(), $event->parent);
    }

    /**
     * A to-many write that moves a member out of another parent changes that parent
     * too, so the caller must be allowed to change it: either the relation's mutate (or
     * the type's update) expression passes for the parent the member leaves, or the
     * related type's update expression passes for the member itself. A member that
     * belongs to no parent needs the latter. An expression that is not declared allows
     * the move, as it would allow the direct write.
     */
    public function onBeforeMemberMove(BeforeMemberMoveEvent $event): void
    {
        if ($event->from !== null
            && $this->allows($event->relation->securityMutate() ?? $this->registry->securityFor($event->type)?->forUpdate(), $event->from)) {
            return;
        }

        $relatedType = $event->relation->relatedTypes()[0] ?? null;
        $memberExpression = $relatedType !== null ? $this->registry->securityFor($relatedType)?->forUpdate() : null;
        if ($this->allows($memberExpression, $event->member)) {
            return;
        }

        throw new AccessDeniedException(\sprintf(
            'Access denied to moving %s into the "%s" relationship of the JSON:API type "%s".',
            $event->member::class,
            $event->relation->name(),
            $event->type,
        ));
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
        if (!\is_string($expression)) {
            return;
        }

        if (!$this->isGranted($expression, $subject)) {
            throw new AccessDeniedException(\sprintf(
                'Access denied to %s on the JSON:API type "%s".',
                $subject::class,
                $type,
            ));
        }
    }

    /**
     * Whether `$expression` allows `$subject`: anything but a string expression (an
     * undeclared or documentation-only value) allows it.
     */
    private function allows(string|bool|null $expression, object $subject): bool
    {
        return !\is_string($expression) || $this->isGranted($expression, $subject);
    }

    /**
     * Evaluates `$expression` through the authorization checker. Without a checker, or
     * without symfony/expression-language, it throws instead of granting.
     */
    private function isGranted(string $expression, mixed $subject): bool
    {
        if ($this->authorizationChecker === null || !\class_exists(Expression::class)) {
            throw new \LogicException(\sprintf(
                'The JSON:API security expression "%s" cannot be evaluated: it needs symfony/security-core, '
                . 'symfony/expression-language and a configured firewall (the security.authorization_checker service).',
                $expression,
            ));
        }

        return $this->authorizationChecker->isGranted(new Expression($expression), $subject);
    }
}
