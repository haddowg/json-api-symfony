<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Opens its resource meta twice.
 */
#[AsJsonApiResource(readOnly: true)]
final class TrTwoEntriesResource extends TrPlainResource
{
    public static string $type = 'tr-bad-entries';

    public function resourceMeta(MetaContext $context): iterable
    {
        return [AdditionalProperties::make(), AdditionalProperties::make()];
    }
}
