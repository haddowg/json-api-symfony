<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes every filter of the inbox and reports the ids of the items it reads.
 */
#[AsJsonApiAction(type: 'inbItems', path: 'tally', scope: ActionScope::Collection, responds: [new MetaResult()], filters: true)]
final class TallyInboxItems implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $ids = [];
        foreach ($context->filtered()->each(10) as $item) {
            $ids[] = $context->serializer()->getId($item);
        }

        return $context->meta(['ids' => $ids]);
    }
}
