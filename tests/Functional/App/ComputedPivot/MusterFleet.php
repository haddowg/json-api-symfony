<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /cp-fleets/{id}/-actions/muster` answers with the fleet it is invoked on, whose
 * `hands` is a pivot relation declared `notSparseField()`.
 */
#[AsJsonApiAction(type: 'cp-fleets', path: 'muster')]
final class MusterFleet implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $fleet = $context->entity();
        \assert($fleet !== null);

        return $context->data($fleet);
    }
}
