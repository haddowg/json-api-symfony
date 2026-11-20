<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteOnlyRelation;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * {@see RevisePinnedComments} called on an author: its input reads as an article, not the
 * resource it was called on, so there is no stored article to compare it with.
 */
#[AsJsonApiAction(
    type: 'authors',
    path: 'revise-article',
    methods: ['PATCH'],
    input: ActionInput::Document,
    inputType: 'articles',
    responds: [new MetaResult()],
)]
final class ReviseArticleFromAuthor implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return (new RevisePinnedComments())->handle($context);
    }
}
