<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `GET /actionWidgets/-actions/elsewhere` declares a bare `SeeOther`, so it stays the
 * plain `303` whatever the request accepts.
 */
#[AsJsonApiAction(
    type: 'actionWidgets',
    path: 'elsewhere',
    methods: ['GET'],
    scope: ActionScope::Collection,
    responds: [new SeeOther()],
)]
final class RedirectWidgets implements ActionHandlerInterface
{
    public function handle(ActionContext $context): SeeOtherResponse
    {
        return $context->seeOther('https://example.com/widgets');
    }
}
