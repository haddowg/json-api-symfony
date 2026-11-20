<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteOnlyRelation;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionInputFactoryInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes an `articles` document by `PATCH` into a {@see PinnedCommentsCommand} it builds
 * itself, and answers with the comment ids it holds. Its locked to-many isn't compared with
 * anything, since the command is no stored article.
 */
#[AsJsonApiAction(
    type: 'articles',
    path: 'revise-command',
    methods: ['PATCH'],
    input: ActionInput::Document,
    inputType: 'articles',
    responds: [new MetaResult()],
)]
final class RevisePinnedCommentsByCommand implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new PinnedCommentsCommand();
    }

    public function handle(ActionContext $context): MetaResponse
    {
        $input = $context->input();
        \assert($input instanceof PinnedCommentsCommand);

        return $context->meta(['pinned' => $input->pinnedComments]);
    }
}
