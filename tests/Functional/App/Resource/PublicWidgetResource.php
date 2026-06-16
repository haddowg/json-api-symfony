<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The default-only resource of the multi-server witness (ADR 0034): exposed on the
 * implicit `default` server (no `server` argument). The self-referential `related`
 * relation renders a convention `links.self` carrying the server's base_uri, so the
 * resolved Server can be asserted by URL.
 */
#[AsJsonApiResource]
final class PublicWidgetResource extends AbstractResource
{
    public static string $type = 'public-widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('related')->type('public-widgets'),
        ];
    }
}
