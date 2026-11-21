<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class PnCardResource extends AbstractResource
{
    public static string $type = 'pn-cards';

    public function fields(): array
    {
        return [
            Id::make()->allowClientId(),
            Str::make('title'),
        ];
    }
}
