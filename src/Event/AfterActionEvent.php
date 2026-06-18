<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

/**
 * Dispatched by the {@see \haddowg\JsonApiBundle\Action\ActionInvoker} *after* the
 * action handler has run (bundle ADR 0076, design §6), for symmetry with the CRUD
 * after-hooks. It carries the action's {@see $type} and {@see $action} name and the
 * {@see $subject} — the resolved entity for a resource-scope action, `null` for a
 * collection-scope action. A public lifecycle event a
 * {@see \haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface} consumer may
 * subscribe to.
 */
final class AfterActionEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $action,
        public readonly ?object $subject,
    ) {}
}
