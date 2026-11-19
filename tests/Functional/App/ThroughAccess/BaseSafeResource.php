<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Safes, which only admins may read.
 */
abstract class BaseSafeResource extends AbstractResource
{
    public static string $type = 'safes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('code'),
        ];
    }
}
