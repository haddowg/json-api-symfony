<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions;

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
 * Takes a `sc-journals` document by POST and answers with the name its input holds and the
 * moods the hook recorded.
 */
#[AsJsonApiAction(
    type: 'sc-journals',
    path: 'record',
    methods: ['POST'],
    scope: ActionScope::Collection,
    input: ActionInput::Document,
    inputType: 'sc-journals',
    responds: [new MetaResult()],
)]
final class RecordScJournal implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new ScJournalInput();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        $input = $context->input();
        \assert($input instanceof ScJournalInput);

        return $context->meta(['name' => $input->name, 'moods' => ScJournalResource::$moods]);
    }
}
