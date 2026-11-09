<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedOnly;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionInputFactoryInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Moves a record to a new imprint, taking the related-only `imprints` type as its request
 * document.
 */
#[AsJsonApiAction(type: 'records', path: 'reimprint', input: ActionInput::Document, inputType: 'imprints')]
final class ReimprintRecord implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new Imprint();
    }

    public function handle(ActionContext $context): DataResponse
    {
        $record = $context->entity();
        \assert($record instanceof Record);
        $imprint = $context->input();
        \assert($imprint instanceof Imprint);

        $record->imprint = new Imprint('i9', $imprint->name);

        return $context->data($record);
    }
}
