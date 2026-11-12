<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Hides every row from the entity manager, so a lookup misses a row the database holds,
 * as it does when a concurrent request inserts the row after the lookup.
 */
final class HideEveryRowFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        return '1 = 0';
    }
}
