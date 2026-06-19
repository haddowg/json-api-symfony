<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\MultiServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The admin server's `admin-items` resource for the multi-server OpenAPI witness —
 * assigned to the named `admin` server, so it appears only in that server's document.
 */
#[AsJsonApiResource(server: 'admin')]
final class AdminItemResource extends AbstractResource
{
    public static string $type = 'admin-items';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
        ];
    }
}
