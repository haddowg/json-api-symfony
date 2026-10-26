<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /actionWidgets/-actions/ingest` takes a body in any of several media types,
 * and reports which one matched and whether it was read as a JSON:API document.
 */
#[AsJsonApiAction(
    type: 'actionWidgets',
    path: 'ingest',
    scope: ActionScope::Collection,
    input: ActionInput::Raw,
    responds: [new MetaResult()],
    accepts: [
        'text/csv',
        'image/*',
        'multipart/form-data',
        'application/vnd.api+json' => ActionInput::Document,
        new AcceptedBody('application/json', schema: ['type' => 'array', 'items' => ['type' => 'string']]),
    ],
)]
final class IngestWidgets implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta([
            'mediaType' => $context->requestMediaType(),
            'document' => $context->input() !== null,
            'bytes' => \strlen((string) $context->request()->getBody()),
        ]);
    }
}
