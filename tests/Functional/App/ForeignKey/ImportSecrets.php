<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionInputFactoryInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes an `fk-imports` document and answers with the user it was posted to.
 */
#[AsJsonApiAction(type: 'fk-users', path: 'import', input: ActionInput::Document, inputType: 'fk-imports')]
final class ImportSecrets implements ActionHandlerInterface, ActionInputFactoryInterface
{
    public function newInput(JsonApiRequestInterface $body): object
    {
        return new FkImport();
    }

    public function handle(ActionContext $context): DataResponse
    {
        $user = $context->entity();
        \assert(\is_object($user));

        return $context->data($user);
    }
}
