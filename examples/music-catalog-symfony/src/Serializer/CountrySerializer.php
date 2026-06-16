<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\Country;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * The reference-data witness: a standalone hand-written serializer for the
 * `countries` type — no entity, no Resource, no hydrator. Read-only, opened to
 * `GET /countries` and `GET /countries/{id}` by the `operations` allow-list. Its
 * rows come from {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\CountryProvider},
 * which sources them from `symfony/intl` — so an external/static data source is a
 * first-class JSON:API collection with no database behind it.
 *
 * Like {@see ChartSerializer} it implements {@see UriTypeAwareInterface} so its URI
 * path segment is `countries`.
 */
#[AsJsonApiSerializer(type: 'countries', operations: [Operation::FetchCollection, Operation::FetchOne])]
final class CountrySerializer extends AbstractSerializer implements UriTypeAwareInterface
{
    public function uriType(): string
    {
        return 'countries';
    }

    public function getType(mixed $object): string
    {
        return 'countries';
    }

    public function getId(mixed $object): string
    {
        \assert($object instanceof Country);

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
            'name' => static fn(mixed $country, JsonApiRequestInterface $request, string $field): string
                => $country instanceof Country ? $country->name : '',
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
