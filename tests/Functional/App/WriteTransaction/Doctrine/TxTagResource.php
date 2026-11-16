<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction\TxTag;

#[AsJsonApiResource(entity: TxTag::class)]
final class TxTagResource extends AbstractResource
{
    public static string $type = 'txTags';

    public function fields(): array
    {
        return [
            Id::make()->requireClientId(),
            Str::make('label'),
        ];
    }
}
