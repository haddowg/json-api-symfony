<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Limits every `parcels` query to the caller's own parcels, or every parcel for an admin.
 */
final class OwnParcelsExtension implements DoctrineExtensionInterface
{
    public function __construct(private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'parcels';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return $builder;
        }

        $alias = $builder->getRootAliases()[0];

        return $builder
            ->andWhere(\sprintf('%s.owner = :own_parcels_owner', $alias))
            ->setParameter('own_parcels_owner', $this->security->getUser()?->getUserIdentifier() ?? '');
    }
}
