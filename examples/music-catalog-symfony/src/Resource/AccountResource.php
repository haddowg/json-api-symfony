<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The `accounts` singleton: the signed-in user's own account, served at `/me` with no id.
 *
 * `GET /me` reads it and `PATCH /me` renames it; `/me/playlists` and
 * `/me/relationships/playlists` reach the user's playlists. It is a third view of the
 * {@see User} entity beside the admin-only `users` and the public `public-profiles`, and
 * like them it keeps the row's real id, so the `PATCH` body carries it.
 *
 * {@see resolveSingletonId()} finds the caller's row by the email the firewall
 * authenticated; an anonymous caller, or one with no row, gets a `404`. The
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Query\OwnAccountExtension} scopes the
 * type to that same row, so no other user's account can render with a `/me` link.
 */
#[AsJsonApiResource(entity: User::class, tags: ['Library'])]
final class AccountResource extends AbstractResource
{
    public static string $type = 'accounts';

    public static string $uriType = 'me';

    public static bool $singleton = true;

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function resolveSingletonId(SingletonContext $context): ?string
    {
        $user = $context->attribute('user');
        if (!$user instanceof UserInterface) {
            return null;
        }

        $account = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $user->getUserIdentifier()]);

        return $account?->id !== null ? (string) $account->id : null;
    }

    public function fields(): array
    {
        return [
            Id::make()->example('1'),
            Email::make('email')->example('ada@example.com')->readOnly(),
            Str::make('displayName')->example('Ada')->minLength(1),
            HasMany::make('playlists', 'playlists'),
        ];
    }
}
