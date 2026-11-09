<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes only the `any` family and reports the ids it reads.
 */
#[AsJsonApiAction(type: 'ffItems', path: 'pick', scope: ActionScope::Collection, responds: [new MetaResult()], filters: ['any'])]
final class PickItems implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $ids = [];
        foreach ($context->filtered()->each() as $item) {
            $ids[] = $context->serializer()->getId($item);
        }

        return $context->meta(['ids' => $ids]);
    }
}
