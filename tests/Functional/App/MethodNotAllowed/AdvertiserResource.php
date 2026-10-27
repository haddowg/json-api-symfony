<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MethodNotAllowed;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Writable, with a read-only relation, so its relationship path is routed for every
 * method and the handler refuses the write.
 */
abstract class AdvertiserResource extends AbstractResource
{
    public static string $type = 'advertisers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsToMany::make('agencies', 'agencies')->readOnly(),
        ];
    }
}
