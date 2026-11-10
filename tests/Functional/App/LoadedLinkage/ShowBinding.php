<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Answers with the binding it is invoked on, in a response it builds itself.
 */
#[AsJsonApiAction(type: 'bindings', path: 'show')]
final class ShowBinding implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        return DataResponse::fromResource($context->entity(), $context->serializer());
    }
}
