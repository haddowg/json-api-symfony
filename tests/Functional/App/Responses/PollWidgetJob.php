<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Responses;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * The async-action projection witness: a collection-scope `GET` action on `widgets`
 * declaring an async response set (core ADR 0127) — `202 Accepted` (a pollable `jobs`
 * document + `Content-Location` + `Retry-After`) and `303 See Other` (the completion
 * redirect) — so the generated OpenAPI document advertises the async-action lifecycle
 * through the custom-action `responds` seam, the action-side twin of the CRUD async
 * write. The runtime response is whatever the handler returns; only the projection is
 * asserted.
 */
#[AsJsonApiAction(
    type: 'widgets',
    path: 'poll',
    methods: ['GET'],
    scope: ActionScope::Collection,
    responds: [new Accepted('jobs'), new SeeOther()],
)]
final class PollWidgetJob implements ActionHandlerInterface
{
    public function handle(ActionContext $context): SeeOtherResponse
    {
        // The projection is what this witness asserts; at runtime it drives the 303 leg.
        return $context->seeOther('https://example.test/widgets/1');
    }
}
