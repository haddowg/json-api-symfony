<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `countries` declaration of the flattened-attribute (`on()`) conformance
 * fixture (bundle ADR 0085): the SECOND hop the book's multi-hop `on('author.country')`
 * walks to. Registering it makes the type known to the serializer resolver and gives the
 * multi-hop eager walk a batching provider for the deeper level — it never renders as a
 * relationship of an author or a book; it exists only so the multi-hop chain has a real
 * type to load at hop 2 (and so `authorCountry` flattens its `name`).
 */
abstract class BaseFlattenCountryResource extends AbstractResource
{
    public static string $type = 'countries';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
