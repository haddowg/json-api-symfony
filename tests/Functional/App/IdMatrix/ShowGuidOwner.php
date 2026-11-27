<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /im-guid-owners/{id}/-actions/show` answers with the owner it is invoked on.
 */
#[AsJsonApiAction(type: 'im-guid-owners', path: 'show')]
final class ShowGuidOwner implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $owner = $context->entity();
        \assert($owner !== null);

        return $context->data($owner);
    }
}
