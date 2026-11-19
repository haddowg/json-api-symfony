<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Hides shelves and books with a subselect over the hidden rows. With {@see $fixedAlias}
 * the subselect names its alias itself, as an extension written before uniqueAlias()
 * would.
 */
final class HiddenRowsExtension implements DoctrineExtensionInterface
{
    public static bool $fixedAlias = false;

    public function supports(string $type): bool
    {
        return $type === 'eaShelves' || $type === 'eaBooks';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $hidden = self::$fixedAlias ? 'hidden_row' : $context->uniqueAlias('hidden_row');
        $root = $builder->getRootAliases()[0];

        return $builder->andWhere(\sprintf(
            "NOT EXISTS (SELECT %1\$s.id FROM %2\$s %1\$s WHERE %1\$s.kind = '%3\$s' AND %1\$s.rowId = %4\$s.id)",
            $hidden,
            EaHiddenRowEntity::class,
            $context->type,
            $root,
        ));
    }
}
