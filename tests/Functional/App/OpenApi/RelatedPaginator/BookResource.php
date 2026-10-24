<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\RelatedPaginator;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class BookResource extends AbstractResource
{
    public static string $type = 'books';

    protected ?int $perPage = 2;

    protected ?int $maxPerPage = 5;

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
