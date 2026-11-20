<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteOnlyRelation;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * The collection twin of {@see RevisePinnedComments}: it takes an `articles` document by
 * `PATCH` with no article to compare its input with.
 */
#[AsJsonApiAction(
    type: 'articles',
    path: 'revise-any',
    methods: ['PATCH'],
    scope: ActionScope::Collection,
    input: ActionInput::Document,
    inputType: 'articles',
    responds: [new MetaResult()],
)]
final class ReviseAnyPinnedComments implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return (new RevisePinnedComments())->handle($context);
    }
}
