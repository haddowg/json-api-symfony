<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\Chart;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * The standalone serialize-plus-fetch witness (capability composition, ADR 0024):
 * a hand-written serializer for the `charts` type registered with
 * {@see AsJsonApiSerializer} — there is **no** `charts` Resource, **no** entity,
 * and **no** hydrator. The type is read-only, opened to exactly two endpoints by
 * the `operations` allow-list (`GET /charts` and `GET /charts/{id}`); without it a
 * standalone serializer defaults to **none** (serialize-only) — the
 * default-operations asymmetry against an `AbstractResource` (which defaults to all
 * five). Its data comes from the custom
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\ChartProvider}.
 *
 * Re-themed verbatim from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Serializer/ChartSerializer.php ChartSerializer}.
 * It implements {@see UriTypeAwareInterface} so its URI path segment resolves
 * explicitly to `charts` (a bare serializer without it falls back to
 * {@see getType()}). The seven {@see \haddowg\JsonApi\Serializer\SerializerInterface}
 * methods are hand-written — `getAttributes()` returns a map of callables, each
 * invoked only for the members that survive sparse-fieldset filtering.
 */
#[AsJsonApiSerializer(type: 'charts', operations: [Operation::FetchCollection, Operation::FetchOne])]
final class ChartSerializer extends AbstractSerializer implements UriTypeAwareInterface
{
    public function uriType(): string
    {
        return 'charts';
    }

    public function getType(mixed $object): string
    {
        return 'charts';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Chart);

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
            'name' => static fn(mixed $chart, JsonApiRequestInterface $request, string $field): string
                => $chart instanceof Chart ? $chart->name : '',
            'period' => static fn(mixed $chart, JsonApiRequestInterface $request, string $field): string
                => $chart instanceof Chart ? $chart->period : '',
            'entries' => static fn(mixed $chart, JsonApiRequestInterface $request, string $field): array
                => $chart instanceof Chart ? $chart->entries : [],
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
