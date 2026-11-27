<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /cp-crews/{id}/-actions/muster` answers with the crew it is invoked on.
 */
#[AsJsonApiAction(type: 'cp-crews', path: 'muster')]
final class MusterCrew implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $crew = $context->entity();
        \assert($crew !== null);

        return $context->data($crew);
    }
}
