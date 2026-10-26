<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `GET /actionWidgets/{id}/-actions/sheet` declares only CSV but answers in a type the
 * request did not negotiate, which the bundle refuses.
 */
#[AsJsonApiAction(type: 'actionWidgets', path: 'sheet', methods: ['GET'], responds: [new RawResult('text/csv')])]
final class MisdeclaredExport implements ActionHandlerInterface
{
    public function handle(ActionContext $context): RawResponse
    {
        return $context->raw('<html></html>', 'text/html');
    }
}
