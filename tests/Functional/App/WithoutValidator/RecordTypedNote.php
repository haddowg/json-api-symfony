<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WithoutValidator;

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
 * Hydrates a `typedNotes` input and answers with its title.
 */
#[AsJsonApiAction(
    type: 'typedNotes',
    path: 'record',
    scope: ActionScope::Collection,
    input: ActionInput::Document,
    inputType: 'typedNotes',
    responds: [new MetaResult()],
)]
final class RecordTypedNote implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new TypedNote();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        $note = $context->input();
        \assert($note instanceof TypedNote);

        return $context->meta(['title' => $note->title]);
    }
}
