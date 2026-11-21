<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Lists the ids of the crates its filters keep.
 */
#[AsJsonApiAction(type: 'crates', path: 'tally', scope: ActionScope::Collection, responds: [new MetaResult()], filters: true)]
final class TallyCrates implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $ids = [];
        foreach ($context->filtered()->each(10) as $crate) {
            $ids[] = $context->serializer()->getId($crate);
        }

        return $context->meta(['ids' => $ids]);
    }
}
