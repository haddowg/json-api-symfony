<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\Resource\ActionRequestMetaContext;
use haddowg\JsonApi\Resource\DeclaresActionRequestMetaInterface;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApiBundle\Action\ActionContext;
use haddowg\JsonApiBundle\Action\ActionHandlerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;

/**
 * `POST /tr-notes/{id}/-actions/archive` takes a `silent` flag in its meta; a `GET` takes
 * none. Either answers with what it read.
 */
#[AsJsonApiAction(type: 'tr-notes', path: 'archive', methods: ['POST', 'GET'], responds: [new MetaResult()])]
final class ArchiveNote implements ActionHandlerInterface, DeclaresActionRequestMetaInterface
{
    public function requestDocumentMeta(ActionRequestMetaContext $context): iterable
    {
        return $context->is('POST') ? [Boolean::make('silent')] : [];
    }

    public function handle(ActionContext $context): MetaResponse
    {
        return $context->meta(['silent' => $context->requestMeta()['silent'] ?? false, 'method' => $context->request()->getMethod()]);
    }
}
