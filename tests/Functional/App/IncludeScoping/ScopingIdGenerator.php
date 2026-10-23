<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;

/**
 * Keeps a seeded id and numbers a created row after the highest stored one, so the
 * fixtures keep their ids and a create needs no client id.
 */
final class ScopingIdGenerator extends AbstractIdGenerator
{
    public function generateId(EntityManagerInterface $em, mixed $entity): mixed
    {
        \assert(\is_object($entity));
        $reflection = new \ReflectionProperty($entity, 'id');
        if ($reflection->isInitialized($entity)) {
            return $reflection->getValue($entity);
        }

        $highest = $em->createQueryBuilder()
            ->select('MAX(e.id)')
            ->from($entity::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();

        return (\is_numeric($highest) ? (int) $highest : 0) + 1;
    }
}
