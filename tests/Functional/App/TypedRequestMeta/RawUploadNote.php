<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Resource\ActionRequestMetaContext;
use haddowg\JsonApi\Resource\DeclaresActionRequestMetaInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * Takes a raw body, which carries no JSON:API meta, yet declares request meta.
 */
#[AsJsonApiAction(type: 'tr-notes', path: 'upload', input: ActionInput::Raw, responds: [new NoContent()])]
final class RawUploadNote implements ActionHandlerInterface, DeclaresActionRequestMetaInterface
{
    public function requestDocumentMeta(ActionRequestMetaContext $context): iterable
    {
        return [Str::make('checksum')];
    }

    public function handle(ActionContext $context): NoContentResponse
    {
        return $context->noContent();
    }
}
