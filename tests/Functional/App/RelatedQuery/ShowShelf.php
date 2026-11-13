<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery;

use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Answers with the shelf it is invoked on.
 */
#[AsJsonApiAction(type: 'shelves', path: 'show', responds: [new ActionResource('shelves')])]
final class ShowShelf implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $shelf = $context->entity();
        \assert($shelf !== null);

        return $context->data($shelf);
    }
}
