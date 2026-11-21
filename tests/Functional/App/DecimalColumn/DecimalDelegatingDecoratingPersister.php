<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use haddowg\JsonApiBundle\DataPersister\StoresDecimalsInterface;

/**
 * A decorating persister that opts into the decimal cast by implementing
 * StoresDecimalsInterface and delegating it to the Doctrine persister.
 */
final class DecimalDelegatingDecoratingPersister extends DateTimeOnlyDecoratingPersister implements StoresDecimalsInterface
{
    public function decimalForColumn(object $entity, string $column, mixed $value): mixed
    {
        self::$calls[] = 'decimalForColumn';

        return $this->inner->decimalForColumn($entity, $column, $value);
    }
}
