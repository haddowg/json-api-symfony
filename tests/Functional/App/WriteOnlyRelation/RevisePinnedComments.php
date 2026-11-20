<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteOnlyRelation;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes an `articles` document by `PATCH` and answers with the ids of the pinned comments
 * its input holds, leaving the article it was called on as it is.
 */
#[AsJsonApiAction(
    type: 'articles',
    path: 'revise',
    methods: ['PATCH'],
    input: ActionInput::Document,
    inputType: 'articles',
    responds: [new MetaResult()],
)]
final class RevisePinnedComments implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $pinned = Accessor::get($context->input(), 'pinnedComments');
        $ids = [];
        foreach (\is_iterable($pinned) ? $pinned : [] as $comment) {
            $id = \is_object($comment) ? Accessor::get($comment, 'id') : null;
            $ids[] = \is_scalar($id) ? (string) $id : '';
        }

        return $context->meta(['pinned' => $ids]);
    }
}
