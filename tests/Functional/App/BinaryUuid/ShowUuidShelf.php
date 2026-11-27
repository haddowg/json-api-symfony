<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /shelves/{id}/-actions/show` answers with the shelf it is invoked on.
 */
#[AsJsonApiAction(type: 'shelves', path: 'show')]
final class ShowUuidShelf implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $entity = $context->entity();
        \assert($entity !== null);

        return $context->data($entity);
    }
}
