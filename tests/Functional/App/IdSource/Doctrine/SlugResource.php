<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The generateUsing witness (bundle ADR 0039): a closure mints the storage key directly
 * when a create omits the id. The closure result is set on the id as-is — it is a
 * storage key, never decoded (only a client wire id decodes). Here it mints a `slug-…`
 * key so the witness can assert the closure ran.
 */
#[AsJsonApiResource(entity: SlugEntity::class)]
final class SlugResource extends AbstractResource
{
    public static string $type = 'slugs';

    public function fields(): array
    {
        return [
            Id::make()->generateUsing(static fn(): string => 'slug-' . \bin2hex(\random_bytes(4))),
            Str::make('title')->required(),
        ];
    }
}
