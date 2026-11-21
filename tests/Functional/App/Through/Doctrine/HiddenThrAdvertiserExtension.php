<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Hides the hidden advertisers from every query for the type.
 */
final class HiddenThrAdvertiserExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return $type === 'thrAdvertisers';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0];

        return $builder->andWhere(\sprintf('%s.hidden = :thr_visible', $alias))->setParameter('thr_visible', false);
    }
}
