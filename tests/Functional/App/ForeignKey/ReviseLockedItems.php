<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes an `fk-users` document by `PATCH`, so a `lockedItems` it names is compared with the
 * items the user holds through their foreign key, and changes nothing.
 */
#[AsJsonApiAction(type: 'fk-users', path: 'revise', methods: ['PATCH'], input: ActionInput::Document, inputType: 'fk-users', responds: [new MetaResult()])]
final class ReviseLockedItems implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['revised' => true]);
    }
}
