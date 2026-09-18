<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedTargetValidation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The same shelf, with `crates` declared **linkage-only**: no related endpoint, so
 * nothing ever has to render or describe a `crates` resource object. The linkage
 * `{type: crates, id}` asserts no shape, which is why an unregistered type is fine
 * there — one of the three resolutions
 * {@see \haddowg\JsonApi\OpenApi\RelatedTypeNotRegistered} names.
 */
final class SafeShelfResource extends AbstractResource
{
    public static string $type = 'shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            HasMany::make('crates', 'crates')->withoutRelatedEndpoint(),
        ];
    }
}
