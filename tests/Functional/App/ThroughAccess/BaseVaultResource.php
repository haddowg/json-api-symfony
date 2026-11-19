<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Vaults, which only admins may list.
 */
abstract class BaseVaultResource extends AbstractResource
{
    public static string $type = 'vaults';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('code'),
        ];
    }
}
