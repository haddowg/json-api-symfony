<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedOnly;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class RecordResource extends AbstractResource
{
    public static string $type = 'records';

    public function fields(): array
    {
        return [
            Id::make()->example('r1'),
            Str::make('title')->example('Debut'),
            BelongsTo::make('imprint', 'imprints')->nullable(),
        ];
    }
}
