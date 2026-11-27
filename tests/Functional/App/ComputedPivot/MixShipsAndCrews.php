<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;

/**
 * `POST /cp-ships/-actions/mix` answers with ship 2 and crew 1 in one collection, each
 * rendered as its own type. Both types name a pivot relation `members`, with different
 * pivot fields.
 */
#[AsJsonApiAction(type: 'cp-ships', path: 'mix', scope: ActionScope::Collection)]
final class MixShipsAndCrews implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): DataResponse
    {
        $ship = $this->providers->forType('cp-ships')->fetchOne('cp-ships', '2');
        $crew = $this->providers->forType('cp-crews')->fetchOne('cp-crews', '1');
        \assert($ship !== null && $crew !== null);

        $server = $context->server();

        return DataResponse::fromCollection([$ship, $crew], new PolymorphicSerializer(
            static fn(mixed $object): SerializerInterface => $server->serializerFor($object instanceof CpCrew ? 'cp-crews' : 'cp-ships'),
            [$server->serializerFor('cp-ships'), $server->serializerFor('cp-crews')],
        ));
    }
}
