<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Hides every agency and member flagged `hidden`, on both servers' types.
 */
final class HiddenRowsExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return \in_array($type, ['agencies', 'members', 'partner-agencies', 'partner-members'], true);
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        return $builder->andWhere(\sprintf('%s.hidden = false', $alias));
    }
}
