<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

/**
 * Dispatched by the {@see \haddowg\JsonApiBundle\Action\ActionInvoker} *after*
 * entity resolution and *before* the action handler runs (bundle ADR 0076,
 * design §6).
 *
 * It carries the action's per-action {@see $security} expression (when declared on
 * the {@see \haddowg\JsonApiBundle\Attribute\AsJsonApiAction}) so the
 * {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber} can evaluate it
 * against the {@see $subject} — the resolved entity for a resource-scope action,
 * `null` for a collection-scope action — and throw to deny with a `403`, exactly
 * mirroring the create/update before-gates. It is also a public lifecycle event:
 * a {@see \haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface} consumer may
 * subscribe and throw a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 * to abort before the handler.
 */
final class BeforeActionEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $action,
        public readonly ?object $subject,
        public readonly ?string $security,
    ) {}
}
