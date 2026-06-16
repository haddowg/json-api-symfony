<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;

/**
 * A standalone serializer for the `colors` type — registered with **no**
 * {@see \haddowg\JsonApi\Resource\AbstractResource} (ADR 0024). The type is
 * serialize-only: it renders as linkage and as an `?include`d resource on a
 * {@see Widget}, but exposes no endpoints of its own.
 */
#[AsJsonApiSerializer(type: 'colors')]
final class ColorSerializer implements SerializerInterface
{
    public function getType(mixed $object): string
    {
        return 'colors';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Color);

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
            'name' => static function (mixed $model, JsonApiRequestInterface $request, string $name): string {
                \assert($model instanceof Color);

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
