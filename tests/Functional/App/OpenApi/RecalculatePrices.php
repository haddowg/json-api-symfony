<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /products/-actions/recalculate-prices` — a collection-scope custom action for
 * the OpenAPI document witness: it carries **explicit OpenAPI tags** (`Catalog`, so it
 * groups with the products resource) and a `security` expression (so the document
 * reports the action as secured, design §4.5/§4.6). The handler returns `204`, so it
 * declares `returns204: true` — the document advertises a `204` response (no body),
 * matching the action's actual behaviour rather than a `200` document body.
 */
#[AsJsonApiAction(
    type: 'products',
    path: 'recalculate-prices',
    scope: ActionScope::Collection,
    returns204: true,
    security: 'true',
    tags: ['Catalog'],
)]
final class RecalculatePrices implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
