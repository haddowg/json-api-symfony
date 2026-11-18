<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Resource\Field\ForeignKey;

/**
 * A persister that writes the foreign key a relation reads through
 * (`HasMany::make('items', 'inbox-items', 'user')`) on the rows of the related type it
 * serves.
 *
 * A relationship write of such a relation never reaches the parent type's persister: the
 * handler works out which rows join the parent and which leave it, from the linkage and the
 * members the relation's reads serve, and hands both to the related type's persister. A
 * related type whose persister doesn't implement this can't be written through a foreign
 * key, and such a write fails.
 */
interface WritesForeignKeysInterface
{
    /**
     * Whether storage refuses a row of `$relatedType` with no value in the foreign key,
     * so a member can't leave the relation without joining another parent.
     */
    public function requiresForeignKey(string $relatedType, ForeignKey $foreignKey): bool;

    /**
     * Points each row in `$attach` at `$parent` and clears the foreign key on each row in
     * `$detach`. A field takes `$parentKey`, the parent's id as storage holds it, and a
     * to-one relation takes `$parent` itself.
     *
     * @param list<object> $attach the rows joining the parent
     * @param list<object> $detach the rows leaving it
     */
    public function writeForeignKey(string $relatedType, ForeignKey $foreignKey, object $parent, mixed $parentKey, array $attach, array $detach, bool $flush = true): void;
}
