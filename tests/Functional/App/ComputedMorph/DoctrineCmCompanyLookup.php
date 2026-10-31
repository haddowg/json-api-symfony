<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

use Doctrine\ORM\EntityManagerInterface;

final class DoctrineCmCompanyLookup implements CmCompanyLookup
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function find(string $type, int $id): ?object
    {
        return $this->entityManager->find(CmCompanyResource::TYPE_TO_CLASS[$type], $id);
    }
}
