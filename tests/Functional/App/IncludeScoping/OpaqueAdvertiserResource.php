<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * An advertiser served by {@see OpaqueScopedProvider}: its relations can be neither
 * batched nor read off the model, so rendering their data is refused.
 */
#[AsJsonApiResource(readOnly: true)]
final class OpaqueAdvertiserResource extends AbstractResource
{
    public static string $type = 'opaque-advertisers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('agencies', 'opaque-agencies'),
            MorphToMany::make('mixedAgencies', ['opaque-agencies', 'opaque-members'])->storedAs('agencies'),
        ];
    }
}
