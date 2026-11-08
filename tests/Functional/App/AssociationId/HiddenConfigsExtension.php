<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\AssociationId;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Hides configs labelled `hidden`, which makes `configs` a scoped type: its linkage and
 * includes then go through the id-IN probes.
 */
final class HiddenConfigsExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return $type === 'configs';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder
            ->andWhere(\sprintf('%s.label <> :hidden_label', $alias))
            ->setParameter('hidden_label', 'hidden');
    }
}
