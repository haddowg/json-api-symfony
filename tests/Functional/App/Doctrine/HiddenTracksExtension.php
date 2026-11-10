<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Scopes `tracks` to leave out every track titled "Hidden".
 */
final class HiddenTracksExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return $type === 'tracks';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder
            ->andWhere(\sprintf('%s.title != :hidden_track', $alias))
            ->setParameter('hidden_track', 'Hidden');
    }
}
