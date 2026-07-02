<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /actionWidgets/{id}/-actions/pin` — the **ungated `asLink`** witness (bundle
 * ADR 0091): a resource-scope action with `asLink: true` and NO `security`, so its
 * `links.pin` member renders on every rendered `actionWidgets` resource (no
 * authorization gate) and resolves to the action's own route URL. The handler itself
 * is a trivial `204`, so it declares `returns204: true`; the suite asserts the
 * *link*, not the side-effect.
 */
#[AsJsonApiAction(type: 'actionWidgets', path: 'pin', returns204: true, asLink: true)]
final class PinWidget implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        \assert($context->entity() instanceof MutableWidget);

        return $context->noContent();
    }
}
