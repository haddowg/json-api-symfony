<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

final class HiddenFillRowsExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return \in_array($type, ['fill-agencies', 'fill-members'], true);
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder->andWhere(\sprintf('%s.hidden = false', $alias));
    }
}
