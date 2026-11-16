<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The `inboxes` access scope on Doctrine: a caller sees only the inbox they own.
 */
final class OwnInboxExtension implements DoctrineExtensionInterface
{
    public function __construct(private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'inboxes';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0];
        $user = $this->security->getUser();
        if ($user === null) {
            return $builder->andWhere('1 = 0');
        }

        return $builder
            ->andWhere(\sprintf('%s.owner = :own_inbox_owner', $alias))
            ->setParameter('own_inbox_owner', $user->getUserIdentifier());
    }
}
