<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A custom serializer for the `gadget` type, proving the generic engine drives
 * reads through a registered override instead of the resource's field DSL (ADR
 * 0023). It renders a distinguishable shape — `name` upper-cased and a
 * `served_by` meta marker — and takes a constructor dependency (the marker
 * value), so the test passing proves it was container-resolved *with* its
 * dependency (a plain `new` would fatal on the missing argument).
 */
final class GadgetSerializer implements SerializerInterface
{
    public function __construct(private readonly string $marker) {}

    public function getType(mixed $object): string
    {
        return 'gadget';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Gadget);

        return $object->id;
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return ['served_by' => $this->marker];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [
            'name' => static function (mixed $model, JsonApiRequestInterface $request, string $name): string {
                \assert($model instanceof Gadget);

                return \strtoupper($model->name);
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
