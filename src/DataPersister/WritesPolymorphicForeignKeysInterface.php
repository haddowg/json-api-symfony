<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Resource\Field\ForeignKey;

/**
 * A persister that also writes a polymorphic foreign key: a relation that names a
 * `MorphTo` declared with `identifiedByColumns()` as its foreign key
 * (`HasMany::make('notes', 'notes', 'owner')`). The row's two fields point it at the
 * parent together, so the write needs the parent's resource type as well as its key.
 */
interface WritesPolymorphicForeignKeysInterface extends WritesForeignKeysInterface
{
    /**
     * Points each row in `$attach` at the parent of `$parentType` stored under
     * `$parentKey`, setting the MorphTo's type field to the stored value naming
     * `$parentType` and its id field to the key, and clears both fields on each row in
     * `$detach`.
     *
     * @param list<object> $attach the rows joining the parent
     * @param list<object> $detach the rows leaving it
     */
    public function writePolymorphicForeignKey(string $relatedType, ForeignKey $foreignKey, string $parentType, mixed $parentKey, array $attach, array $detach, bool $flush = true): void;
}
