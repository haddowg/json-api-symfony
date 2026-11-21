<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\DeclaresResourceMetaInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * A type composed without a resource, whose serializer declares its resource objects'
 * meta and supplies it through getMeta(). Asked by `X-Bogus-Meta`, getMeta() adds a
 * member the declaration does not name.
 */
#[AsJsonApiSerializer(type: 'tr-labels', operations: [Operation::FetchCollection, Operation::FetchOne])]
final class TrLabelSerializer implements SerializerInterface, DeclaresResourceMetaInterface
{
    public function resourceMeta(MetaContext $context): iterable
    {
        return [Integer::make('uses')->readOnly()];
    }

    public function getType(mixed $object): string
    {
        return 'tr-labels';
    }

    public function getId(mixed $object): string
    {
        return $object instanceof TrLabel ? $object->id : '';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return $request->hasHeader('X-Bogus-Meta') ? ['uses' => 2, 'bogus' => true] : ['uses' => 2];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return ['name' => static fn(mixed $label): string => $label instanceof TrLabel ? $label->name : ''];
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
