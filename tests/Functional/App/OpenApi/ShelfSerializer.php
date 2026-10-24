<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * A standalone serializer (no resource) that exposes only `GET /shelves/{id}` and
 * declares vendor extensions: a type-wide `x-feature` and an `x-cache` on FetchOne.
 */
#[AsJsonApiSerializer(
    type: 'shelves',
    operations: [Operation::FetchOne],
    extensions: ['x-feature' => 'shelving'],
    operationExtensions: [Operation::FetchOne->name => ['x-cache' => 'long']],
)]
final class ShelfSerializer implements SerializerInterface
{
    public function getType(mixed $object): string
    {
        return 'shelves';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Shelf);

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
            'name' => static function (mixed $model): string {
                \assert($model instanceof Shelf);

                return $model->name;
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
