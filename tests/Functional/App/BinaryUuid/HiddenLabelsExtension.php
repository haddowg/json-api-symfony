<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Hides labels named `hidden`, which makes `labels` a scoped type: its linkage and
 * includes then go through the id-IN probes.
 */
final class HiddenLabelsExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return $type === 'labels';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder
            ->andWhere(\sprintf('%s.name <> :hidden_label', $alias))
            ->setParameter('hidden_label', 'hidden');
    }
}
