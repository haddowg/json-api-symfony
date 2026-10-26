<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The same rows as `notes`, on the `gappy` server, with no field examples at all, so
 * every one of its operations is missing its example.
 */
abstract class ScrapResource extends AbstractResource
{
    public static string $type = 'scraps';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
