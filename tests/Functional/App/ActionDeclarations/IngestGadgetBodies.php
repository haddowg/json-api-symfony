<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionDeclarations;

use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * A Raw action whose `AcceptedBody` is read as a `Document` but names no `inputType`,
 * which fails the build.
 */
#[AsJsonApiAction(
    type: 'guard-gadgets',
    path: 'ingest-bodies',
    scope: ActionScope::Collection,
    input: ActionInput::Raw,
    responds: [new NoContent()],
    accepts: [new AcceptedBody('application/vnd.api+json', document: true)],
)]
final class IngestGadgetBodies implements ActionHandlerInterface
{
    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
