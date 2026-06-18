<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /actionWidgets/{id}/-actions/archive` — the per-action **`security`** witness
 * (bundle ADR 0076, design §6/§10): the action carries
 * `security: "is_granted('ROLE_ADMIN')"`, evaluated at the `BeforeActionEvent` gate
 * against the resolved entity *after* it is fetched and *before* the handler runs.
 * A `ROLE_USER` request is denied with a `403`; a `ROLE_ADMIN` request reaches the
 * handler (a `204`). The handler body never runs on a deny.
 */
#[AsJsonApiAction(type: 'actionWidgets', path: 'archive', security: "is_granted('ROLE_ADMIN')")]
final class ArchiveWidget implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        \assert($context->entity() instanceof MutableWidget);

        return $context->noContent();
    }
}
