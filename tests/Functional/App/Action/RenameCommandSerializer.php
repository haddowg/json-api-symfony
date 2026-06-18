<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;

/**
 * A standalone serializer for the bespoke `renameCommands` command type (bundle ADR
 * 0076, design §3): registered with no {@see \haddowg\JsonApi\Resource\AbstractResource}
 * and no endpoints of its own. It exists so the type is registered (its serializer
 * resolves) — the command rides into a custom action as its `inputType`. It is paired
 * with {@see RenameCommandHydrator} (which hydrates the request document onto the DTO).
 */
#[AsJsonApiSerializer(type: 'renameCommands')]
final class RenameCommandSerializer implements SerializerInterface
{
    public function getType(mixed $object): string
    {
        return 'renameCommands';
    }

    public function getId(mixed $object): string
    {
        return 'command';
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
            'newName' => static function (mixed $model): string {
                \assert($model instanceof RenameCommand);

                return $model->newName;
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
