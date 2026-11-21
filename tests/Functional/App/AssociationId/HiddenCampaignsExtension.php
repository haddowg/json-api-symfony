<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\AssociationId;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Hides campaign 20, so a path that applied the campaign's scope to the config keyed by
 * it would lose config 20.
 */
final class HiddenCampaignsExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return $type === 'campaigns';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder
            ->andWhere(\sprintf('%s.id <> :hidden_campaign', $alias))
            ->setParameter('hidden_campaign', 20);
    }
}
