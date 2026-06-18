<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `PATCH /actionWidgets/-actions/recalculate` — the **method-mismatch** witness
 * (bundle ADR 0076, design §10): the action declares `methods: ['PATCH']`, so the
 * route loader emits the route only for `PATCH`. A `POST` to the same path matches no
 * route method and Symfony `405`s natively (mapped by the exception listener) — proving
 * the author-declared method allow-list is enforced at routing, before any handler.
 */
#[AsJsonApiAction(type: 'actionWidgets', path: 'recalculate', methods: ['PATCH'], scope: ActionScope::Collection)]
final class RecalculateWidgets implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['recalculated' => true]);
    }
}
