<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Query;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The access scope of the `accounts` singleton: every query for the type sees only the
 * signed-in user's row, and an anonymous caller sees none. A singleton's links are all
 * `/me`, so its data layer must never expose anyone else's account.
 */
final class OwnAccountExtension implements DoctrineExtensionInterface
{
    public function __construct(private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'accounts';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0]
            ?? throw new \LogicException('The builder arrived without a root alias.');

        $user = $this->security->getUser();
        if ($user === null) {
            return $builder->andWhere('1 = 0');
        }

        return $builder
            ->andWhere(\sprintf('%s.email = :own_account_email', $alias))
            ->setParameter('own_account_email', $user->getUserIdentifier());
    }
}
