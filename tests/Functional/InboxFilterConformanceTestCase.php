<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\InboxFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * `WhereSet` and `WhereMorph`, asserted identically against the in-memory provider
 * ({@see InMemoryInboxFilterTest}) and the Doctrine provider
 * ({@see DoctrineInboxFilterTest}) over the inbox {@see InboxFixtures} describes.
 */
#[Group('spec:fetching-filtering')]
abstract class InboxFilterConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string QUERIES_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    #[Test]
    public function archivedItemsAreLeftOutByDefault(): void
    {
        self::assertSame(['1', '2', '5'], $this->itemIds(''));
    }

    #[Test]
    public function aClientAsksForArchivedOnlyOrForBoth(): void
    {
        self::assertSame(['3', '4'], $this->itemIds('filter[archived]=true'));
        self::assertSame(['1', '2', '3', '4', '5'], $this->itemIds('filter[archived]=true,false'));
        self::assertSame(['1', '2', '3', '4', '5'], $this->itemIds('filter[archived][]=true&filter[archived][]=false'));
        self::assertSame(['1', '2', '5'], $this->itemIds('filter[archived]=false'));
    }

    #[Test]
    public function aSecondSetFilterNarrowsAlongsideTheDefault(): void
    {
        self::assertSame(['2'], $this->itemIds('filter[read]=true'));
        self::assertSame(['2', '4'], $this->itemIds('filter[read]=1&filter[archived]=true,false'));
        self::assertSame(['1', '3', '5'], $this->itemIds('filter[read]=no&filter[archived]=yes,no'));
    }

    #[Test]
    public function aValueThatIsNotABooleanIsRejected(): void
    {
        $this->assertRejected('filter[archived]=banana', 'filter[archived]');
        $this->assertRejected('filter[archived]=true,maybe', 'filter[archived]');
        $this->assertRejected('filter[archived][x]=true', 'filter[archived]');
    }

    #[Test]
    public function aTypeAloneMatchesEveryItemAboutThatType(): void
    {
        self::assertSame(['1', '2'], $this->itemIds('filter[subject][type]=inbRfps'));
        self::assertSame(['3', '4'], $this->itemIds('filter[subject][type]=inbCampaigns&filter[archived]=true,false'));
    }

    #[Test]
    public function idsNarrowInEitherForm(): void
    {
        self::assertSame(['1'], $this->itemIds('filter[subject][type]=inbRfps&filter[subject][id]=812'));
        self::assertSame(['1', '2'], $this->itemIds('filter[subject][type]=inbRfps&filter[subject][id]=812,813'));
        self::assertSame(['2'], $this->itemIds('filter[subject][type]=inbRfps&filter[subject][id][]=813'));
        self::assertSame([], $this->itemIds('filter[subject][type]=inbRfps&filter[subject][id]=814'));
    }

    #[Test]
    public function anIdIsDecodedThroughItsTypesEncoder(): void
    {
        self::assertSame(['3'], $this->itemIds('filter[subject][type]=inbCampaigns&filter[subject][id]=cmp-1&filter[archived]=true,false'));
    }

    #[Test]
    public function aMalformedMorphValueIsRejectedAtItsMember(): void
    {
        $malformed = [
            'filter[subject]=inbRfps' => 'filter[subject]',
            'filter[subject][inbRfps]=812' => 'filter[subject]',
            'filter[subject][id]=812' => 'filter[subject][type]',
            'filter[subject][type]=inbFolders' => 'filter[subject][type]',
            'filter[subject][type][]=inbRfps&filter[subject][type][]=inbCampaigns' => 'filter[subject][type]',
            'filter[subject][type]=inbRfps&filter[subject][id]=abc' => 'filter[subject][id]',
            'filter[subject][type]=inbCampaigns&filter[subject][id]=1' => 'filter[subject][id]',
            'filter[subject][type]=inbRfps&filter[subject][id]=812,' => 'filter[subject][id]',
        ];

        foreach ($malformed as $query => $parameter) {
            $this->assertRejected($query, $parameter);
        }
    }

    #[Test]
    public function aMorphToManyMatchesAnItemWithAMemberOfThatType(): void
    {
        self::assertSame(['1', '3'], $this->itemIds('filter[linked][type]=inbRfps&filter[archived]=true,false'));
        self::assertSame(['3'], $this->itemIds('filter[linked][type]=inbCampaigns&filter[linked][id]=cmp-2&filter[archived]=true,false'));
        self::assertSame(['2', '3'], $this->itemIds('filter[linked][type]=inbCampaigns&filter[archived]=true,false'));
    }

    #[Test]
    public function aRelatedEndpointAppliesTheDefaultUnlessTheRelationDropsIt(): void
    {
        self::assertSame(['1', '2'], $this->idsOf($this->handle('/inbFolders/1/items')));
        self::assertSame(['3'], $this->idsOf($this->handle('/inbFolders/1/items?filter[archived]=true')));
        self::assertSame(['1', '2', '3'], $this->idsOf($this->handle('/inbFolders/1/everything')));
        self::assertSame(['1'], $this->idsOf($this->handle('/inbFolders/1/everything?filter[subject][type]=inbRfps&filter[subject][id]=812')));
    }

    #[Test]
    public function relatedQueryFiltersEachParentsItems(): void
    {
        $response = $this->handle('/inbFolders?include=items&relatedQuery[items][filter][archived]=true&relatedQuery[items][filter][subject][type]=inbCampaigns', extraServer: ['HTTP_ACCEPT' => self::QUERIES_ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $folders = $this->decode($response)['data'] ?? null;
        self::assertIsArray($folders);
        $linkage = [];
        foreach ($folders as $folder) {
            self::assertIsArray($folder);
            $relationships = $folder['relationships'] ?? null;
            self::assertIsArray($relationships);
            $items = $relationships['items'] ?? null;
            self::assertIsArray($items);
            $data = $items['data'] ?? null;
            self::assertIsArray($data);
            $id = $folder['id'] ?? null;
            self::assertIsString($id);
            $linkage[$id] = \array_column($data, 'id');
        }

        self::assertSame(['1' => ['3'], '2' => ['4']], $linkage);
    }

    #[Test]
    public function aCollectionActionTakesBothFilters(): void
    {
        self::assertSame(['1', '2', '5'], $this->tally(''));
        self::assertSame(['1', '2'], $this->tally('?filter[subject][type]=inbRfps'));
        self::assertSame(['4'], $this->tally('?filter[subject][type]=inbCampaigns&filter[read]=true&filter[archived]=true'));

        $response = $this->handle('/inbItems/-actions/tally?filter[subject][type]=nope', 'POST');
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('openapi')]
    public function theOpenApiDocumentDescribesBothFilters(): void
    {
        $response = $this->handle('/docs.json');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $parameters = $this->filterParameters($this->decode($response), '/inbItems');

        self::assertSame(['type' => 'array', 'default' => [false], 'items' => ['type' => 'boolean']], $parameters['filter[archived]']['schema'] ?? null);
        self::assertSame(['key' => 'archived', 'delimited' => true], $parameters['filter[archived][]']['x-filter-list'] ?? null);
        self::assertSame('deepObject', $parameters['filter[subject]']['style'] ?? null);
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['inbRfps', 'inbCampaigns']],
                'id' => ['anyOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]]],
            ],
            'required' => ['type'],
            'additionalProperties' => false,
        ], $parameters['filter[subject]']['schema'] ?? null);
        self::assertSame(['type' => 'inbRfps', 'id' => ['812']], $parameters['filter[subject]']['example'] ?? null);
    }

    /**
     * @return list<string>
     */
    protected function itemIds(string $query): array
    {
        return $this->idsOf($this->handle('/inbItems' . ($query === '' ? '' : '?' . $query)));
    }

    /**
     * @return list<string>
     */
    protected function idsOf(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('inbItems', $resource['type'] ?? null);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function tally(string $query): array
    {
        $response = $this->handle('/inbItems/-actions/tally' . $query, 'POST');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $meta = $this->decode($response)['meta'] ?? null;
        self::assertIsArray($meta);
        $ids = $meta['ids'] ?? null;
        self::assertIsArray($ids);
        $ids = \array_map(static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '', $ids);
        \sort($ids);

        return $ids;
    }

    private function assertRejected(string $query, string $parameter): void
    {
        $response = $this->handle('/inbItems?' . $query);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null, (string) $response->getContent());
        $source = $error['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame($parameter, $source['parameter'] ?? null, (string) $response->getContent());
    }

    /**
     * The `filter[…]` parameters of a path's `GET`, keyed by name.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, array<string, mixed>>
     */
    private function filterParameters(array $document, string $path): array
    {
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);
        $item = $paths[$path] ?? null;
        self::assertIsArray($item, $path);
        $operation = $item['get'] ?? null;
        self::assertIsArray($operation, $path);
        $declared = $operation['parameters'] ?? null;
        self::assertIsArray($declared);
        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $shared = $components['parameters'] ?? [];
        self::assertIsArray($shared);

        $parameters = [];
        foreach ($declared as $parameter) {
            self::assertIsArray($parameter);
            $ref = $parameter['$ref'] ?? null;
            if (\is_string($ref)) {
                $parameter = $shared[\substr($ref, \strlen('#/components/parameters/'))] ?? null;
                self::assertIsArray($parameter);
            }
            $name = $parameter['name'] ?? null;
            if (\is_string($name) && \str_starts_with($name, 'filter[')) {
                /** @var array<string, mixed> $parameter */
                $parameters[$name] = $parameter;
            }
        }

        return $parameters;
    }
}
