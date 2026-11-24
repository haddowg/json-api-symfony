<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Hides rows named or titled `Hidden`, of which the seed has none, so every type is a
 * scoped one and its reads, linkage, link rows and morph filters go through the id
 * probes and admitted-id lists.
 */
final class ImHiddenRowsExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return \str_starts_with($type, 'im-');
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');
        $field = $context->type === 'im-notes' || $context->type === 'im-posts' || $context->type === 'im-videos' ? 'title' : 'name';

        return $builder
            ->andWhere(\sprintf('%s.%s <> :im_hidden', $alias, $field))
            ->setParameter('im_hidden', 'Hidden');
    }
}
