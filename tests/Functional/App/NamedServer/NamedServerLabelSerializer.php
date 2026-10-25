<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * The named server's standalone `labels` serializer.
 */
#[AsJsonApiSerializer(type: 'labels', server: 'v5', operations: [Operation::FetchOne])]
final class NamedServerLabelSerializer implements SerializerInterface
{
    public function getType(mixed $object): string
    {
        return 'labels';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Note);

        return (string) $object->id;
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
        return ['source' => static fn(): string => 'v5'];
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
