<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * A type composed without a resource, whose serializer declares its collection's meta.
 */
#[AsJsonApiSerializer(type: 'tm-labels', operations: [Operation::FetchCollection, Operation::FetchOne])]
final class TmLabelSerializer implements SerializerInterface, DeclaresDocumentMetaInterface
{
    public function documentMeta(OperationType $operation, ?string $relation = null): iterable
    {
        return $operation === OperationType::FetchCollection
            ? [Integer::make('labels')->required()->example(2)->extractUsing(static fn(mixed $labels): int => \is_array($labels) ? \count($labels) : 0)]
            : [];
    }

    public function getType(mixed $object): string
    {
        return 'tm-labels';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof TmLabel);

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
            'name' => static function (mixed $label): string {
                \assert($label instanceof TmLabel);

                return $label->name;
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
