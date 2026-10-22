<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The `venues` fields both output-timezone kernels share, so an included venue's
 * `DateTime` shows the server zone reaching `included`.
 */
abstract class BaseVenueResource extends AbstractResource
{
    public static string $type = 'venues';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            DateTime::make('openedAt'),
        ];
    }
}
