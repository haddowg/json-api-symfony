<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

use haddowg\JsonApiBundle\Event\AfterFetchOneEvent;
use haddowg\JsonApiBundle\Event\BeforeActionEvent;
use haddowg\JsonApiBundle\Event\BeforeCreateEvent;
use haddowg\JsonApiBundle\Event\BeforeDeleteEvent;
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
 *  - {@see BeforeRelationshipMutateEvent} → the parent (gated by the update expression);
 *  - {@see BeforeDeleteEvent}             → the loaded entity;
 *  - {@see AfterFetchOneEvent}            → the loaded entity.
 *
 * Collection reads are intentionally **not** gated here: row-level read
 * authorization belongs in the query-scope (a Doctrine extension hiding rows → `404`),
 * not a single all-or-nothing gate. A type that declared no security is never gated
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
            BeforeActionEvent::class => 'onBeforeAction',
        ];
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
        // Relationship mutation is gated by the update expression, with the parent
        // resource as the subject.
        $this->authorize($event->type, $this->registry->securityFor($event->type)?->forUpdate(), $event->parent);
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
     * Evaluates `$expression` (when the operation is gated) against `$subject`,
     * throwing {@see AccessDeniedException} when it is false. A `null` expression
     * leaves the operation ungated.
     */
    private function authorize(string $type, ?string $expression, object $subject): void
    {
        if ($expression === null || $this->authorizationChecker === null) {
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
