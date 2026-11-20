<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface;
use haddowg\JsonApi\Serializer\DeclaresResourceMetaInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * A label read as a badge, whose resource and document meta each open to extra members an
 * entry returns. Under `X-Clash-Resource-Meta` or `X-Clash-Document-Meta` that entry also
 * returns a declared member's name.
 */
#[AsJsonApiSerializer(type: 'tr-badges', operations: [Operation::FetchOne])]
final class TrBadgeSerializer implements SerializerInterface, DeclaresResourceMetaInterface, DeclaresDocumentMetaInterface
{
    public function resourceMeta(MetaContext $context): iterable
    {
        return [
            Integer::make('uses')->readOnly()->extractUsing(static fn(TrLabel $label): int => 2),
            AdditionalProperties::make()->extractUsing(
                static fn(TrLabel $label, JsonApiRequestInterface $request): array => $request->hasHeader('X-Clash-Resource-Meta') ? ['uses' => 99, 'colour' => 'red'] : ['colour' => 'red'],
            ),
        ];
    }

    public function documentMeta(OperationType $operation): iterable
    {
        return match ($operation) {
            OperationType::FetchOne => [
                Integer::make('rank')->extractUsing(static fn(mixed $label): int => 1),
                AdditionalProperties::make()->extractUsing(
                    static fn(mixed $label, JsonApiRequestInterface $request): array => $request->hasHeader('X-Clash-Document-Meta') ? ['rank' => 7, 'shelf' => 'top'] : ['shelf' => 'top'],
                ),
            ],
            default => [],
        };
    }

    public function getType(mixed $object): string
    {
        return 'tr-badges';
    }

    public function getId(mixed $object): string
    {
        return $object instanceof TrLabel ? $object->id : '';
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
