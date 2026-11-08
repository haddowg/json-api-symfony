<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes only the `status` filter.
 */
#[AsJsonApiAction(type: 'filterCrates', path: 'audit', scope: ActionScope::Collection, responds: [new MetaResult()], filters: ['status'])]
final class AuditCrates implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $ids = [];
        foreach ($context->filtered()->each() as $crate) {
            $ids[] = $context->serializer()->getId($crate);
        }

        return $context->meta(['ids' => $ids]);
    }
}
