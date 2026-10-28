<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeHydration;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;

/**
 * Fetch-joins a book's genre, opting in to the include hydration load only. Every
 * purpose it sees is recorded, and `$enabled` lets a test measure the lazy baseline.
 */
final class GenreFetchJoinExtension implements DoctrineExtensionInterface
{
    public bool $enabled = true;

    /**
     * @var list<QueryPurpose>
     */
    public array $applied = [];

    public function supports(string $type): bool
    {
        return $type === 'books';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $this->applied[] = $context->purpose;

        if (!$this->enabled || $context->purpose !== QueryPurpose::IncludeHydration) {
            return $builder;
        }

        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder
            ->leftJoin(\sprintf('%s.genre', $alias), 'fetched_genre')
            ->addSelect('fetched_genre');
    }
}
