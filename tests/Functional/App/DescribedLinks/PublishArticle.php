<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DescribedLinks;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `PATCH /articles/{id}/-actions/publish`, rendered as a `publish` link on every
 * article. It declares PATCH first so the suite can tell the link's `describedby`
 * names the first declared method rather than a POST default.
 */
#[AsJsonApiAction(type: 'articles', path: 'publish', methods: ['PATCH', 'POST'], responds: [new NoContent()], asLink: true)]
final class PublishArticle implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
