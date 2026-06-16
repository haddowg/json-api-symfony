<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The both-servers resource of the multi-server witness (ADR 0034): exposed on
 * **both** the `default` and `admin` servers (`server: ['default', 'admin']`), so
 * the same type is reachable from each — at the root and under `/admin` — with each
 * route resolving its own Server (distinct base_uri in the rendered links).
 */
#[AsJsonApiResource(server: ['default', 'admin'])]
final class SharedWidgetResource extends AbstractResource
{
    public static string $type = 'shared-widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('related')->type('shared-widgets'),
        ];
    }
}
