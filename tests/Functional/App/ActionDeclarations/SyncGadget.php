<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * A valid multi-method action with declared safety and its own extension.
 */
#[AsJsonApiAction(type: 'guard-gadgets', path: 'sync', methods: ['GET', 'POST'], readOnly: false, idempotent: true, extensions: ['x-workflows' => ['workflowId' => 'syncGadget']], responds: [new NoContent()])]
final class SyncGadget implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
