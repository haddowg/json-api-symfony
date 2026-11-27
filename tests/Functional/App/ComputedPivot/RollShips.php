<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;

/**
 * `POST /cp-ships/-actions/roll` answers with every ship, in a collection it builds
 * itself.
 */
#[AsJsonApiAction(type: 'cp-ships', path: 'roll', scope: ActionScope::Collection)]
final class RollShips implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): DataResponse
    {
        $ships = [];
        foreach (['1', '2', '3'] as $id) {
            $ship = $this->providers->forType('cp-ships')->fetchOne('cp-ships', $id);
            \assert($ship !== null);
            $ships[] = $ship;
        }

        return DataResponse::fromCollection($ships, $context->serializer());
    }
}
