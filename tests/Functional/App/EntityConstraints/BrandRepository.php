<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntityConstraints;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Brand>
 */
final class BrandRepository extends EntityRepository
{
    /**
     * The brands matching `$criteria` that are not archived: the rows a name must be
     * unique among.
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<Brand>
     */
    public function findUnarchivedBy(array $criteria): array
    {
        return \array_values($this->findBy([...$criteria, 'archived' => false]));
    }
}
