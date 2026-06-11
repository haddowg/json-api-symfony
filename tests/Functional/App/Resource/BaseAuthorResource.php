<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `authors` declaration both functional kernels serve — the related
 * type an article's to-one `author` relationship links to. Minimal: an id and a
 * single `name` attribute. Registering it makes the type known to the
 * serializer resolver, so {@see \haddowg\JsonApi\Resource\Field\BelongsTo} can
 * emit `{type: 'authors', id: …}` linkage.
 */
abstract class BaseAuthorResource extends AbstractResource
{
    public static string $type = 'authors';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
