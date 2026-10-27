<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionLinks;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /articles/{id}/-actions/feature`, gated to admins, so its link needs both the
 * security check and the action link's condition to pass.
 */
#[AsJsonApiAction(type: 'articles', path: 'feature', responds: [new NoContent()], security: "is_granted('ROLE_ADMIN')")]
final class FeatureArticle implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
