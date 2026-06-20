<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `authors` declaration of the flattened-attribute (`on()`) conformance
 * fixture (bundle ADR 0085): the related type a {@see BaseFlattenBookResource}'s
 * hidden `author` relation points at. Registering it makes the type known to the
 * serializer resolver and gives the book's eager loader a batching provider. The
 * `name` is the member a book's `authorName` flattens; re-fetching `/authors/{id}`
 * is the witness that a flattened write mutated the author in place.
 *
 * It also declares a HIDDEN to-one `country` (pointing at `countries`): the SECOND
 * hop the book's multi-hop `authorName`-sibling `on('author.country')` walks to.
 * Hidden, so it never renders as a relationship of an author — the multi-hop eager walk
 * still loads it (load-not-render) by resolving it hidden-inclusively.
 */
abstract class BaseFlattenAuthorResource extends AbstractResource
{
    public static string $type = 'authors';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            // The hidden second-hop backing relation the multi-hop on('author.country')
            // walks to.
            BelongsTo::make('country')->type('countries')->hidden(),
        ];
    }
}
