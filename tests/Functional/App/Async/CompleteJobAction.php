<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Async;

use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /jobs/-actions/complete` — the completion half of the async lifecycle: it
 * stands in for the job-status endpoint a client polls, returning a `303 See Other`
 * ({@see ActionContext::seeOther()}) that redirects to the resource the finished
 * operation produced. The witness that a custom action can drive the `303` leg of the
 * async story (bundle ADR 0110).
 */
#[AsJsonApiAction(type: 'jobs', path: 'complete', scope: ActionScope::Collection, returns204: true)]
final class CompleteJobAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): SeeOtherResponse
    {
        return $context->seeOther('https://example.test/articles/1');
    }
}
