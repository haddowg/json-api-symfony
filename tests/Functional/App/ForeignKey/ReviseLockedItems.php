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
 * items the user holds through their foreign key, and changes nothing. It answers with the
 * ids of the items its input holds, which {@see FkUser} declares a property for.
 */
#[AsJsonApiAction(type: 'fk-users', path: 'revise', methods: ['PATCH'], input: ActionInput::Document, inputType: 'fk-users', responds: [new MetaResult()])]
final class ReviseLockedItems implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse
    {
        $input = $context->input();
        \assert($input instanceof FkUser);

        return $context->meta(['revised' => true, 'lockedItems' => \array_map(
            static fn(object $item): ?string => $item instanceof FkItem ? (string) $item->id : null,
            $input->lockedItems,
        )]);
    }
}
