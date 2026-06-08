<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;

/**
 * An application-style extension scoping `articles` to `category = 'guide'`,
 * registered by plain autoconfiguration in the
 * {@see DoctrineExtensionTestKernel} — the exact shape a user writes for a
 * published-only / tenant / soft-delete base constraint. Per the
 * {@see QueryPurpose} contract it applies unconditionally, recording each
 * purpose it saw so the test can assert which query path invoked it.
 */
final class GuideOnlyArticlesExtension implements DoctrineExtensionInterface
{
    /**
     * @var list<QueryPurpose>
     */
    public array $applied = [];

    public function supports(string $type): bool
    {
        return $type === 'articles';
    }

    public function apply(QueryBuilder $builder, string $type, QueryPurpose $purpose): QueryBuilder
    {
        $this->applied[] = $purpose;

        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder
            ->andWhere(\sprintf('%s.category = :guide_only', $alias))
            ->setParameter('guide_only', 'guide');
    }
}
