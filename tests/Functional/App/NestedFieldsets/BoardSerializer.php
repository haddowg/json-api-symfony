<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NestedFieldsets;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PanelEntity;

/**
 * Renders a panel as a `boards` resource with every attribute whole, as a serializer
 * written before nested fieldsets would. Its field names make the server check
 * `fields[boards]` strictly.
 */
class BoardSerializer implements SerializerInterface, DeclaresFieldNamesInterface
{
    public function getType(mixed $object): string
    {
        return 'boards';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Panel || $object instanceof PanelEntity);

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
        \assert($object instanceof Panel || $object instanceof PanelEntity);

        return [
            'name' => static fn(): string => $object->name,
            'values' => static fn(): ?array => $object->values,
            'address' => static fn(): ?array => $object->address,
            'rows' => static fn(): ?array => $object->rows,
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

    public function declaredFieldNames(): array
    {
        return ['id', 'name', 'values', 'address', 'rows'];
    }
}
