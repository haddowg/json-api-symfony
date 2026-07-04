<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Async;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;

/**
 * A standalone serializer for the `jobs` type — no resource, no endpoints. The async
 * write witness ({@see \haddowg\JsonApiBundle\Tests\Functional\AsyncWriteTest}) renders
 * the accepted operation's {@see Job} through this serializer as the `202` body's
 * primary `data`, proving the handler resolves the job type's serializer via the
 * server (`serializerFor('jobs')`).
 */
#[AsJsonApiSerializer(type: 'jobs')]
final class JobSerializer implements SerializerInterface
{
    public function getType(mixed $object): string
    {
        return 'jobs';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Job);

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
            'status' => static function (mixed $model): string {
                \assert($model instanceof Job);

                return $model->status;
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
