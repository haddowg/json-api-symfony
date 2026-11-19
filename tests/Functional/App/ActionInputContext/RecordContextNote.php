<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionInputContext;

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
 * Hydrates a `contextNotes` input by `POST` or `PATCH` and answers with what it holds.
 */
#[AsJsonApiAction(
    type: 'contextNotes',
    path: 'record',
    methods: ['POST', 'PATCH'],
    scope: ActionScope::Collection,
    input: ActionInput::Document,
    inputType: 'contextNotes',
    responds: [new MetaResult()],
)]
final class RecordContextNote implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new ContextNote();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        $note = $context->input();
        \assert($note instanceof ContextNote);

        return $context->meta(['code' => $note->code, 'note' => $note->note]);
    }
}
