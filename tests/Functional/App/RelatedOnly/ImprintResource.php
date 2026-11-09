<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedOnly;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The related-only witness: `imprints` has no endpoint of its own and is reached through
 * a record's `imprint` relation, or as the `reimprint` action's request document.
 */
#[AsJsonApiResource(relatedOnly: true)]
final class ImprintResource extends AbstractResource
{
    public static string $type = 'imprints';

    public function fields(): array
    {
        return [
            Id::make()->example('i1'),
            Str::make('name')->required()->example('Island'),
            BelongsTo::make('parent', 'imprints')->nullable(),
        ];
    }
}
