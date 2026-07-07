<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /products/-actions/summarize` — the **meta-output** action witness for the
 * OpenAPI document (core ADR 0102): a collection-scope action that returns a meta-only
 * JSON:API document, so it declares `responds: [new MetaResult()]`. The generated
 * document advertises a `200` referencing the shared `MetaDocument` component instead of
 * a products resource body, and the component itself joins the schema set — proving the
 * bundle wires the meta response through the descriptor to core's projection.
 */
#[AsJsonApiAction(
    type: 'products',
    path: 'summarize',
    scope: ActionScope::Collection,
    responds: [new MetaResult()],
    tags: ['Catalog'],
)]
final class SummarizeCatalog implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['products' => ['total' => 0]]);
    }
}
