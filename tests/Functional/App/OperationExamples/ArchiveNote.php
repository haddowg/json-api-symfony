<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Declares no example, so it keeps the generated `notes` document.
 */
#[AsJsonApiAction(type: 'notes', path: 'archive')]
final class ArchiveNote implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $note = $context->entity();
        \assert($note instanceof Note);

        return $context->data($note);
    }
}
