<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;

/**
 * Answers with a collection of templates it builds the response for itself.
 */
#[AsJsonApiAction(type: 'templates', path: 'list', scope: ActionScope::Collection)]
final class ListTemplates implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): DataResponse
    {
        $template = $this->providers->forType('templates')->fetchOne('templates', '1');
        \assert($template !== null);

        return DataResponse::fromCollection([$template], $context->serializer());
    }
}
