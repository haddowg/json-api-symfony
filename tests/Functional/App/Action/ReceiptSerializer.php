<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;

/**
 * A standalone serializer for the bespoke `receipts` response type (bundle ADR 0076,
 * design §4): registered with no resource and no endpoints. A custom action whose
 * `outputType` is `receipts` returns a {@see Receipt} that renders through this
 * serializer, proving the response document is decoupled from the mount type.
 */
#[AsJsonApiSerializer(type: 'receipts')]
final class ReceiptSerializer implements SerializerInterface
{
    public function getType(mixed $object): string
    {
        return 'receipts';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Receipt);

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
            'appliedName' => static function (mixed $model): string {
                \assert($model instanceof Receipt);

                return $model->appliedName;
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
