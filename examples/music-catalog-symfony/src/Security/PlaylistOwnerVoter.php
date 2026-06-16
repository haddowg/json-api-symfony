<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Security;

use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Playlist;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The Voter backing the `is_granted('EDIT', object)` ownership gate on
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\PlaylistResource}
 * (bundle ADR 0043, docs/authorization.md). It grants `EDIT` on a {@see Playlist}
 * when the authenticated user's identifier equals the playlist owner's email — so
 * `securityUpdate: "is_granted('EDIT', object)"` lets only a playlist's owner
 * update it, while everyone else gets a `403` (an unauthenticated client a `401`).
 *
 * It is an ordinary Symfony Voter: registered via the example's `services.yaml`
 * autoconfiguration (which tags it `security.voter`), it needs no bundle wiring.
 * The security expression delegates to it purely because the gated operation's
 * entity is passed to `is_granted()` as the second argument (`object`).
 *
 * @extends Voter<string, Playlist>
 */
final class PlaylistOwnerVoter extends Voter
{
    private const string EDIT = 'EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT && $subject instanceof Playlist;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof Playlist);

        $user = $token->getUser();
        if ($user === null) {
            return false;
        }

        // The in-memory firewall keys users by username; the seeded playlist owner
        // is the user with that email, so the owner identifier is the email.
        return $subject->owner !== null
            && $user->getUserIdentifier() === $subject->owner->email;
    }
}
