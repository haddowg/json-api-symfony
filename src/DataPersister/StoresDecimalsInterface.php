<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

/**
 * A persister whose storage needs a number in a particular form before it is written.
 * The Doctrine persister implements it to write a number to a `decimal` column as the
 * decimal string Doctrine reads that column as. The write handler asks it about each
 * pivot value it writes to a declared link row. A persister that decorates the Doctrine
 * persister keeps that conversion by implementing this interface and delegating to it;
 * without it, the link row receives the value as the pivot field cast it.
 */
interface StoresDecimalsInterface
{
    /**
     * The value to write to `$column` of `$entity` for `$value`: a number as the decimal
     * string a `decimal` column stores, anything else unchanged.
     */
    public function decimalForColumn(object $entity, string $column, mixed $value): mixed;
}
