<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Attributes and object members named like Symfony's Collection constraint options,
 * shared by both providers. Each one carries a rule so validation has work to do.
 */
abstract class BasePresetResource extends AbstractResource
{
    public static string $type = 'presets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('groups')->required()->maxLength(10),
            Str::make('fields')->nullable()->maxLength(10),
            Str::make('payload')->nullable()->maxLength(10),
            Obj::make('options')->nullable()->fields(
                Str::make('groups')->required()->maxLength(10),
                Str::make('fields')->maxLength(10),
                Str::make('payload')->maxLength(10),
                Str::make('allowMissingFields')->maxLength(10),
            ),
        ];
    }
}
