<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\SingletonContext;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The `inboxes` singleton, served at `/inbox` for the signed-in user.
 *
 * The id comes from {@see IDS} by user: ada owns inbox 1 and grace inbox 2. hopper has
 * none, ghost names a row that doesn't exist, and mallory names grace's, which the type's
 * access scope keeps from her.
 */
abstract class InboxResource extends AbstractResource
{
    public const array IDS = ['ada' => '1', 'grace' => '2', 'ghost' => '99', 'mallory' => '2'];

    public static string $type = 'inboxes';

    public static string $uriType = 'inbox';

    public static bool $singleton = true;

    /**
     * The last context the type resolved an id from.
     */
    public static ?SingletonContext $lastContext = null;

    public function resolveSingletonId(SingletonContext $context): ?string
    {
        self::$lastContext = $context;
        $user = $context->attribute('user');

        return $user instanceof UserInterface ? (self::IDS[$user->getUserIdentifier()] ?? null) : null;
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Integer::make('unread')->readOnly(),
            BelongsTo::make('pinned', 'inbox-items')->nullable(),
            HasMany::make('items', 'inbox-items'),
        ];
    }
}
