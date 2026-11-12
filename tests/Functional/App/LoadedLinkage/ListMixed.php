<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;

/**
 * Answers with template 1 and binding 1 in one collection, each rendered as its own
 * type.
 */
#[AsJsonApiAction(type: 'templates', path: 'mixed', scope: ActionScope::Collection)]
final class ListMixed implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): DataResponse
    {
        $template = $this->providers->forType('templates')->fetchOne('templates', '1');
        $binding = $this->providers->forType('bindings')->fetchOne('bindings', '1');
        \assert($template !== null && $binding !== null);

        $server = $context->server();

        return DataResponse::fromCollection([$template, $binding], new PolymorphicSerializer(
            static fn(mixed $object): SerializerInterface => $server->serializerFor($object instanceof LinkageBinding ? 'bindings' : 'templates'),
            [$server->serializerFor('templates'), $server->serializerFor('bindings')],
        ));
    }
}
