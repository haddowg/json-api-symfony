<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /actionWidgets/-actions/import` — the **collection-scope** witness (bundle
 * ADR 0076, design §10): no `{id}`, so {@see ActionContext::entity()} is `null`. The
 * handler returns a meta-only document (so it declares `responds: [new MetaResult()]`), proving a
 * collection-scope action dispatches and renders without resolving an entity.
 */
#[AsJsonApiAction(type: 'actionWidgets', path: 'import', scope: ActionScope::Collection, responds: [new MetaResult()])]
final class ImportWidgets implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        // A collection-scope action carries no entity.
        \assert($context->entity() === null);

        return $context->meta(['imported' => 3]);
    }
}
