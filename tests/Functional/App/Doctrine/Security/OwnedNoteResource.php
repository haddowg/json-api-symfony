<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The `ownedNotes` resource: owner-only, each note in at most one folder.
 */
#[AsJsonApiResource(
    entity: OwnedNoteEntity::class,
    security: "is_granted('EDIT', object)",
    securityList: "is_granted('ROLE_USER')",
)]
final class OwnedNoteResource extends AbstractResource
{
    public static string $type = 'ownedNotes';

    public function __construct()
    {
        $this->countable();
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('owner'),
            BelongsTo::make('folder', 'ownedFolders')->nullable(),
            // Flattened onto the linked folder, so a write lands on a row of another type.
            Str::make('folderOwner')->storedAs('owner')->on('folder'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('owner'),
        ];
    }
}
