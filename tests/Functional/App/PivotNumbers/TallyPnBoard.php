<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionInputFactoryInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes a `pn-boards` document as its input and answers once it validated.
 */
#[AsJsonApiAction(
    type: 'pn-boards',
    path: 'tally',
    scope: ActionScope::Collection,
    input: ActionInput::Document,
    inputType: 'pn-boards',
    responds: [new MetaResult()],
)]
final class TallyPnBoard implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new PnBoard();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['tallied' => true]);
    }
}
