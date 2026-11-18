<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;

/**
 * Hides the hidden `fk-secrets` from every query for the type.
 */
final class FkSecretExtension implements DoctrineExtensionInterface
{
    public function supports(string $type): bool
    {
        return $type === 'fk-secrets';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0] ?? 'related';

        return $builder->andWhere(\sprintf('%s.hidden = false', $alias));
    }
}
