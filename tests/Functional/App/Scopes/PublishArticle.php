<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /articles/{id}/-actions/publish`, which declares its own scope.
 */
#[AsJsonApiAction(type: 'articles', path: 'publish', responds: [new NoContent()], scopes: ['articles:publish'])]
final class PublishArticle implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
