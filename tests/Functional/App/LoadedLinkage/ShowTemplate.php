<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Answers with the template it is invoked on, through the action context.
 */
#[AsJsonApiAction(type: 'templates', path: 'show')]
final class ShowTemplate implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $template = $context->entity();
        \assert($template !== null);

        return $context->data($template);
    }
}
