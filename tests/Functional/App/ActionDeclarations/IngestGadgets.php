<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * A Raw action that reads one accepted media type as a `Document` but names no
 * `inputType`, which fails the build.
 */
#[AsJsonApiAction(
    type: 'guard-gadgets',
    path: 'ingest',
    scope: ActionScope::Collection,
    input: ActionInput::Raw,
    responds: [new NoContent()],
    accepts: ['text/csv', 'application/vnd.api+json' => ActionInput::Document],
)]
final class IngestGadgets implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
