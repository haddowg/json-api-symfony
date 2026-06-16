<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The Voter backing the `is_granted('EDIT', object)` ownership gate on
 * {@see OwnedWidgetResource}: it grants `EDIT` on an {@see OwnedWidgetEntity} when
 * the authenticated user's identifier equals the entity's `owner`. The keystone
 * per-object authorization the security expression delegates to (bundle ADR 0043).
 *
 * @extends Voter<string, OwnedWidgetEntity>
 */
final class OwnedWidgetVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'EDIT' && $subject instanceof OwnedWidgetEntity;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof OwnedWidgetEntity);
        $user = $token->getUser();
        if ($user === null) {
            return false;
        }

        return $user->getUserIdentifier() === $subject->owner;
    }
}
