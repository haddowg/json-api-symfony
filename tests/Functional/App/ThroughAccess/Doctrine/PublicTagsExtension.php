<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Hides secret tags from everyone but admins.
 */
final class PublicTagsExtension implements DoctrineExtensionInterface
{
    public function __construct(private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'tags';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return $builder;
        }

        return $builder->andWhere(\sprintf('%s.secret = false', $builder->getRootAliases()[0]));
    }
}
