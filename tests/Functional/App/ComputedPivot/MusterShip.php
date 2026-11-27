<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /cp-ships/{id}/-actions/muster` answers with the ship it is invoked on, so its
 * crew's `members` is a pivot relation one include below the action's response.
 */
#[AsJsonApiAction(type: 'cp-ships', path: 'muster')]
final class MusterShip implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $ship = $context->entity();
        \assert($ship !== null);

        return $context->data($ship);
    }
}
