<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Declares a collection member named after the `page` core writes there.
 */
#[AsJsonApiResource(readOnly: true)]
final class TmClashResource extends TmBoardResource
{
    public static string $type = 'tm-clashes';

    public function documentMeta(OperationType $operation): iterable
    {
        return $operation === OperationType::FetchCollection ? [Integer::make('page')] : [];
    }
}
