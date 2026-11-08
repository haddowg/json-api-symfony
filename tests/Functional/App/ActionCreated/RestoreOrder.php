<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionCreated;

use haddowg\JsonApi\OpenApi\Metadata\ActionCreated;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /createdOrders/{id}/-actions/restore` — declares both a `200` and a `201`
 * document, so the handler picks: order `2` is treated as recreated and answered `201`,
 * any other keeps the `200`.
 */
#[AsJsonApiAction(type: 'createdOrders', path: 'restore', responds: [new ActionResource('createdOrders'), new ActionCreated('createdOrders')])]
final class RestoreOrder implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $order = $context->entity();
        \assert($order instanceof Order);
        $response = $context->data($order);

        return $order->id === '2' ? $response->withStatus(201) : $response;
    }
}
