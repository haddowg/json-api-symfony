<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OwnedRows;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class MemoResource extends AbstractResource
{
    public static string $type = 'memos';

    public function __construct()
    {
        $this->countable();
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('owner'),
            Str::make('text'),
        ];
    }
}
