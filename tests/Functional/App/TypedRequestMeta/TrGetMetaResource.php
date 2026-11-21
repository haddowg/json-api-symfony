<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Overrides the deprecated getMeta() while declaring its resource meta.
 */
#[AsJsonApiResource(readOnly: true)]
final class TrGetMetaResource extends TrPlainResource
{
    public static string $type = 'tr-bad-get-meta';

    public function resourceMeta(MetaContext $context): iterable
    {
        return [Integer::make('uses')->readOnly()];
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return ['uses' => 1];
    }
}
