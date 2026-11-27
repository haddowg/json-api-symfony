<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /cp-crews/{id}/-actions/queue` answers `202 Accepted` with the crew as its job
 * resource.
 */
#[AsJsonApiAction(type: 'cp-crews', path: 'queue', responds: [new Accepted('cp-crews')])]
final class QueueCrew implements ActionHandlerInterface
{
    public function handle(ActionContext $context): AcceptedResponse
    {
        $crew = $context->entity();
        \assert($crew instanceof CpCrew);

        return $context->accepted('https://example.test/cp-crews/' . $crew->id, $crew);
    }
}
