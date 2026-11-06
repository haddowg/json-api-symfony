<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

#[AsJsonApiAction(type: 'bulletins', path: 'pin')]
final class PinBulletin implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $bulletin = $context->entity();
        \assert($bulletin instanceof Bulletin);

        return $context->data($bulletin);
    }
}
