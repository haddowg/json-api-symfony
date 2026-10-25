<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The `ownedFolders` resource: owner-only like `ownedWidgets`, with a `notes`
 * to-many on the inverse side of the folder/notes one-to-many, so writing it moves
 * notes between folders.
 */
#[AsJsonApiResource(
    entity: OwnedFolderEntity::class,
    security: "is_granted('EDIT', object)",
    // Any user may read a folder, so a flattened write onto one they can't edit is refused
    // by the update gate rather than reading as a missing row.
    securityRead: "is_granted('ROLE_USER')",
    securityList: "is_granted('ROLE_USER')",
)]
final class OwnedFolderResource extends AbstractResource
{
    public static string $type = 'ownedFolders';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('owner'),
            HasMany::make('notes', 'ownedNotes'),
        ];
    }
}
