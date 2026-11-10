<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * The Doctrine kernel's access scope: a hidden binding is in no read.
 */
final class HiddenBindingsExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return $type === 'bindings';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder->andWhere(\sprintf('%s.hidden = false', $alias));
    }
}
