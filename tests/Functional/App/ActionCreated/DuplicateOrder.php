<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionCreated;

use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /createdOrders/{id}/-actions/duplicate` — creates a copy of the order and answers
 * with it. It declares only an {@see ActionCreated}, so the response is sent as `201`
 * without the handler setting a status.
 */
#[AsJsonApiAction(type: 'createdOrders', path: 'duplicate', responds: [new ActionCreated('createdOrders')])]
final class DuplicateOrder implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $order = $context->entity();
        \assert($order instanceof Order);
        $copy = new Order($order->id . '-copy', $order->reference . ' (copy)');

        return $context->data($copy)->withHeader('Location', 'https://example.test/createdOrders/' . $copy->id);
    }
}
