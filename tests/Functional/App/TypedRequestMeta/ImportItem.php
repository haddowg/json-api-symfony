<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /tr-items/-actions/import` reads an item document, its `data.meta` validated and
 * hydrated as a create of an item would be, and answers with the item it built.
 */
#[AsJsonApiAction(type: 'tr-items', path: 'import', scope: ActionScope::Collection, input: ActionInput::Document, inputType: 'tr-items', responds: [new ActionResource('tr-items')])]
final class ImportItem implements ActionHandlerInterface
{
    public function handle(ActionContext $context): DataResponse
    {
        $item = $context->input();
        \assert($item instanceof TrItem);
        $item->id ??= 99;

        return $context->data($item);
    }
}
