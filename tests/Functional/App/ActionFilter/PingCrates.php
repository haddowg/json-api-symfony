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
 * A collection action taking no filters.
 */
#[AsJsonApiAction(type: 'filterCrates', path: 'ping', scope: ActionScope::Collection, responds: [new MetaResult()])]
final class PingCrates implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        try {
            $context->filtered();
        } catch (\LogicException $e) {
            return $context->meta(['filtered' => $e->getMessage()]);
        }

        return $context->meta(['filtered' => null]);
    }
}
