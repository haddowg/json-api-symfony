<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SecurityEnforcement;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * A custom action declaring an enforced security expression, mounted on the
 * otherwise unsecured {@see PlainGadgetResource}.
 */
#[AsJsonApiAction(type: 'guard-gadgets', path: 'inspect', responds: [new NoContent()], security: "is_granted('ROLE_ADMIN')")]
final class InspectGadget implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
