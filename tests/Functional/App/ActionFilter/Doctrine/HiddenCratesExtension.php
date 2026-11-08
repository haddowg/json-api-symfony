<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Hides a crate flagged `hidden` from every query, and records each context it sees.
 */
final class HiddenCratesExtension implements DoctrineExtensionInterface
{
    /**
     * @var list<ExtensionContext>
     */
    public static array $seen = [];

    public function supports(string $type): bool
    {
        return $type === 'filterCrates';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        self::$seen[] = $context;
        $alias = $builder->getRootAliases()[0];

        return $builder->andWhere($alias . '.hidden = :hiddenCrate')->setParameter('hiddenCrate', false);
    }
}
