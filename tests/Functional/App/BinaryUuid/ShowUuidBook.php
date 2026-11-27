<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /books/{id}/-actions/show` answers with the book it is invoked on.
 */
#[AsJsonApiAction(type: 'books', path: 'show')]
final class ShowUuidBook implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $entity = $context->entity();
        \assert($entity !== null);

        return $context->data($entity);
    }
}
