<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * A standalone serializer for the `beacons` type — registered with **no**
 * {@see \haddowg\JsonApi\Resource\AbstractResource} (ADR 0024) — that opts into a
 * single endpoint via the operations allow-list (ADR 0025): only
 * {@see Operation::FetchOne}, so just `GET /beacons/{id}` is routed. A standalone
 * serializer is serialize-only by default; this witnesses opening one read route.
 */
#[AsJsonApiSerializer(type: 'beacons', operations: [Operation::FetchOne])]
final class BeaconSerializer implements SerializerInterface
{
    public function getType(mixed $object): string
    {
        return 'beacons';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Beacon);

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
                \assert($model instanceof Beacon);

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
