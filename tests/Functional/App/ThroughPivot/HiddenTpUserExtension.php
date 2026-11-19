<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Hides the hidden users from every query for the type.
 */
final class HiddenTpUserExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return $type === 'tpUsers';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0];

        return $builder->andWhere(\sprintf('%s.hidden = :tp_visible', $alias))->setParameter('tp_visible', false);
    }
}
