<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\GateOrder;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * An action only an admin may call.
 */
#[AsJsonApiAction(type: 'gateWidgets', path: 'poke', responds: [new NoContent()], security: GateSecurity::ADMIN)]
final readonly class PokeGateWidget implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        GateLog::$entries[] = 'poke';

        return $context->noContent();
    }
}
