<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EncodedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class EncPlayerResource extends AbstractResource
{
    public static string $type = 'enc-players';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixEncoder('player-')),
            Str::make('name'),
        ];
    }
}
