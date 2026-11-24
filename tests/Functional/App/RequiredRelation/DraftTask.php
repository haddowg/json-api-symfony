<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation;

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
 * Takes a `tasks` document under the type's create rules and answers with its title.
 */
#[AsJsonApiAction(
    type: 'tasks',
    path: 'draft',
    methods: ['POST'],
    scope: ActionScope::Collection,
    input: ActionInput::Document,
    inputType: 'tasks',
    responds: [new MetaResult()],
)]
final class DraftTask implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new Task();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        $task = $context->input();
        \assert($task instanceof Task);

        return $context->meta(['title' => $task->title, 'owner' => $task->owner?->id]);
    }
}
