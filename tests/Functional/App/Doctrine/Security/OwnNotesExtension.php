<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Limits every `ownedNotes` query to the signed-in user's notes, the example in the
 * authorization guide's "Scoping rows to the current user" section.
 */
final class OwnNotesExtension implements DoctrineExtensionInterface
{
    public function __construct(private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'ownedNotes';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0];
        $user = $this->security->getUser();
        if ($user === null) {
            return $builder->andWhere('1 = 0');
        }

        return $builder
            ->andWhere(\sprintf('%s.owner = :own_notes_owner', $alias))
            ->setParameter('own_notes_owner', $user->getUserIdentifier());
    }
}
