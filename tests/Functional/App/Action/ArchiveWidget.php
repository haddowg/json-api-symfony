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
 *
 * It also declares `asLink: true` (bundle ADR 0091), so it is the **security-aware
 * resource-link** witness: a `links.archive` member renders on `GET
 * /actionWidgets/{id}` only for a requester who would pass the same `ROLE_ADMIN`
 * gate — present for `admin`, absent for `user`.
 */
#[AsJsonApiAction(type: 'actionWidgets', path: 'archive', security: "is_granted('ROLE_ADMIN')", asLink: true)]
final class ArchiveWidget implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        \assert($context->entity() instanceof MutableWidget);

        return $context->noContent();
    }
}
