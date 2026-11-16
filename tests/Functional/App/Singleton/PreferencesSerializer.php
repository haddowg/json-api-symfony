<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApi\Resource\SingletonResourceInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A singleton composed without a resource: the serializer declares it, and with no
 * hydrator the type serves `GET /preferences` alone.
 */
#[AsJsonApiSerializer(type: 'preferences')]
final class PreferencesSerializer implements SerializerInterface, SingletonResourceInterface
{
    public static function isSingleton(): bool
    {
        return true;
    }

    public function resolveSingletonId(SingletonContext $context): ?string
    {
        $user = $context->attribute('user');

        return $user instanceof UserInterface ? 'p-' . $user->getUserIdentifier() : null;
    }

    public function getType(mixed $object): string
    {
        return 'preferences';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Preferences);

        return $object->id;
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [
            'theme' => static function (mixed $model): string {
                \assert($model instanceof Preferences);

                return $model->theme;
            },
        ];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }
}
