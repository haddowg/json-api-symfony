<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Answers with the note, so its generated example is a `notes` document; the attribute
 * replaces it.
 */
#[AsJsonApiAction(type: 'notes', path: 'pin', example: new OperationExample(responses: [200 => NoteExamples::PIN_RESPONSE]))]
final class PinNote implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $note = $context->entity();
        \assert($note instanceof Note);

        return $context->data($note);
    }
}
