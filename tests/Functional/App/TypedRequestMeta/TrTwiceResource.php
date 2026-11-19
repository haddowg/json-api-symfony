<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\RequestMetaContext;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Declares one request meta member twice.
 */
#[AsJsonApiResource(readOnly: true)]
final class TrTwiceResource extends TrPlainResource
{
    public static string $type = 'tr-bad-twice';

    public function requestDocumentMeta(RequestMetaContext $context): iterable
    {
        return [Str::make('reason'), Str::make('reason')];
    }
}
