<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /shelves/-actions/restock`, a custom action mounted on the standalone `shelves`
 * type, so the document shows the serializer's type-wide extension reaching it.
 */
#[AsJsonApiAction(
    type: 'shelves',
    path: 'restock',
    scope: ActionScope::Collection,
    responds: [new NoContent()],
)]
final class RestockShelves implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
