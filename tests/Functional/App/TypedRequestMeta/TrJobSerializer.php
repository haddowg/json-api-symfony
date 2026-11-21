<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;

/**
 * The job a write accepted for later processing answers with.
 */
#[AsJsonApiSerializer(type: 'tr-jobs')]
final class TrJobSerializer implements SerializerInterface
{
    public function getType(mixed $object): string
    {
        return 'tr-jobs';
    }

    public function getId(mixed $object): string
    {
        return $object instanceof TrJob ? $object->id : '';
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
        return ['status' => static fn(mixed $job): string => $job instanceof TrJob ? $job->status : ''];
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
