<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedQuery;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;

/**
 * Answers with shelf 1 and book 1 in one collection, each rendered as its own type.
 */
#[AsJsonApiAction(type: 'shelves', path: 'mix', scope: ActionScope::Collection)]
final class MixShelves implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): DataResponse
    {
        $shelf = $this->providers->forType('shelves')->fetchOne('shelves', '1');
        $book = $this->providers->forType('books')->fetchOne('books', '1');
        \assert($shelf !== null && $book !== null);

        $server = $context->server();

        return DataResponse::fromCollection([$shelf, $book], new PolymorphicSerializer(
            static fn(mixed $object): SerializerInterface => $server->serializerFor($object instanceof RqBook ? 'books' : 'shelves'),
            [$server->serializerFor('shelves'), $server->serializerFor('books')],
        ));
    }
}
