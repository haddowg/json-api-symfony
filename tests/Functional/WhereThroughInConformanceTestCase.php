<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\Filter\WhereThroughInBuilder;
use haddowg\JsonApiBundle\DataProvider\FilterPaths;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine\LeadEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\ThroughInFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * `WhereThroughIn` and relationship-ending `WhereThrough` paths, asserted identically
 * against the in-memory provider ({@see InMemoryWhereThroughInTest}) and the Doctrine
 * provider ({@see DoctrineWhereThroughInTest}). The lead graph is
 * {@see ThroughInFixtures}: leads 1 and 4 run in Acme's spring campaign, 2 in Globex's
 * summer one, 3 in Initech's autumn one, and 5 in none; leads 1 and 5 use template
 * `tpl-1`, 1 and 2 use `tpl-2`, and 4 uses `tpl-3`.
 */
#[Group('spec:fetching-filtering')]
abstract class WhereThroughInConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * Applies a resolved `WhereThroughIn` with `$value` straight through the provider's
     * filter handler, as no request can send an empty list.
     *
     * @return list<string> the matching lead ids
     */
    abstract protected function appliedDirectly(WhereThroughIn $filter, mixed $value): array;

    #[Test]
    public function aDelimitedListMatchesAnyAdvertiserTwoHopsAway(): void
    {
        self::assertSame(['1', '2', '4'], $this->leadIds('filter[advertiser]=4,7'));
    }

    #[Test]
    public function anArrayListMatchesAnyAdvertiserTwoHopsAway(): void
    {
        self::assertSame(['2', '3'], $this->leadIds('filter[advertiser][]=7&filter[advertiser][]=9'));
    }

    #[Test]
    public function aNonIntegerAdvertiserIdIsRejectedAtTheParameter(): void
    {
        $this->assertRejected('filter[advertiser]=4,banana', 'filter[advertiser]');
    }

    #[Test]
    public function aSingleToOneSegmentMatchesUuidCampaignIds(): void
    {
        self::assertSame(['2', '3'], $this->leadIds('filter[campaign]=' . ThroughInFixtures::SUMMER . ',' . ThroughInFixtures::AUTUMN));
    }

    #[Test]
    public function aMalformedUuidIsRejectedAtTheParameter(): void
    {
        $this->assertRejected('filter[campaign]=' . ThroughInFixtures::SUMMER . ',not-a-uuid', 'filter[campaign]');
    }

    #[Test]
    public function aToManyLeafDecodesEachWireIdThroughTheRelatedEncoder(): void
    {
        self::assertSame(['1', '2', '4'], $this->leadIds('filter[template]=tpl-2,tpl-3'));
    }

    #[Test]
    public function aWireIdTheRelatedEncoderCannotDecodeIsRejectedAtTheParameter(): void
    {
        $this->assertRejected('filter[template]=tpl-1,2', 'filter[template]');
    }

    #[Test]
    public function aMultiHopPathEndingOnAFieldMatchesAnyListedValue(): void
    {
        self::assertSame(['2', '3'], $this->leadIds('filter[advertiserName]=Globex,Initech'));
    }

    #[Test]
    public function anUndelimitedListTakesOnlyTheArrayForm(): void
    {
        self::assertSame(['1', '4'], $this->leadIds('filter[campaignName][]=Spring'));
        $this->assertRejected('filter[campaignName]=Spring', 'filter[campaignName]');
    }

    #[Test]
    public function aListMatchingNothingReturnsAnEmptyCollection(): void
    {
        self::assertSame([], $this->leadIds('filter[advertiserName]=Nobody'));
    }

    #[Test]
    public function anEmptyListMatchesNothing(): void
    {
        self::assertSame([], $this->appliedDirectly($this->resolved(WhereThroughIn::make('advertiser', 'campaign.advertiser')), []));
        self::assertSame([], $this->appliedDirectly($this->resolved(WhereThroughIn::make('advertiserName', 'campaign.advertiser.name')), []));
    }

    #[Test]
    public function aWhereThroughEndingOnAToOneComparesTheRelatedId(): void
    {
        self::assertSame(['2'], $this->leadIds('filter[advertiserIs]=7'));
        $this->assertRejected('filter[advertiserIs]=banana', 'filter[advertiserIs]');
    }

    #[Test]
    public function aWhereThroughEndingOnAToManyComparesTheDecodedRelatedId(): void
    {
        self::assertSame(['1', '5'], $this->leadIds('filter[usesTemplate]=tpl-1'));
        $this->assertRejected('filter[usesTemplate]=1', 'filter[usesTemplate]');
    }

    #[Test]
    public function aWhereAllGroupAppliesTheTraversalWithItsOtherChildren(): void
    {
        // Leads 1 and 4 are Acme's; only lead 1 is open.
        self::assertSame(['1'], $this->leadIds('filter[openForAdvertiser]=4'));
    }

    #[Test]
    public function aWhereAnyGroupMatchesEitherTraversal(): void
    {
        // Lead 3 is Initech's; lead 4 uses the Survey template.
        self::assertSame(['3', '4'], $this->leadIds('filter[advertiserOrTemplateName]=Initech,Survey'));
    }

    #[Test]
    public function aFilterFamilyResolvesItsTraversalPerRequest(): void
    {
        self::assertSame(['2'], $this->leadIds('filter[via.advertiser]=7'));
        $this->assertRejected('filter[via.advertiser]=banana', 'filter[via.advertiser]');
    }

    #[Test]
    public function aRelatedEndpointResolvesThePathAgainstTheRelatedType(): void
    {
        $response = $this->handle('/wtiCampaigns/' . ThroughInFixtures::SPRING . '/leads?filter[template]=tpl-3');

        self::assertSame(['4'], $this->idsOf($response));
    }

    #[Test]
    #[Group('openapi')]
    public function theOpenApiDocumentTypesARelationshipLeafByTheRelatedId(): void
    {
        $response = $this->handle('/docs.json');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        foreach (['/wtiLeads', '/wtiCampaigns/{id}/leads'] as $path) {
            $parameters = $this->filterParameters($document, $path);

            self::assertSame(['type' => 'array', 'items' => ['type' => 'integer']], $parameters['filter[advertiser]']['schema'] ?? null, $path);
            self::assertSame(['key' => 'advertiser', 'delimited' => true], $parameters['filter[advertiser][]']['x-filter-list'] ?? null, $path);
            self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']], $parameters['filter[campaign]']['schema'] ?? null, $path);
            self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $parameters['filter[template]']['schema'] ?? null, $path);
            self::assertSame(['type' => 'integer'], $parameters['filter[advertiserIs]']['schema'] ?? null, $path);
        }
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

    /**
     * @param array<mixed> $rows lead entities
     *
     * @return list<string>
     */
    protected function leadIdsOf(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            self::assertInstanceOf(LeadEntity::class, $row);
            $ids[] = (string) $row->id;
        }

        return $ids;
    }

    /**
     * `$filter` resolved against the leads type, as the data layer resolves it.
     */
    protected function resolved(WhereThroughInBuilder $builder): WhereThroughIn
    {
        $server = static::getContainer()->get(ServerProvider::class);
        self::assertInstanceOf(ServerProvider::class, $server);

        $resolved = FilterPaths::of($server->get(), $server->get()->resourceFor('wtiLeads'))?->resolve($builder->build());
        self::assertInstanceOf(WhereThroughIn::class, $resolved);

        return $resolved;
    }

    /**
     * @return list<string>
     */
    protected function leadIds(string $query): array
    {
        return $this->idsOf($this->handle('/wtiLeads?' . $query));
    }

    /**
     * @return list<string>
     */
    protected function idsOf(\Symfony\Component\HttpFoundation\Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('wtiLeads', $resource['type'] ?? null);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        \sort($ids, \SORT_NUMERIC);

        return $ids;
    }

    private function assertRejected(string $query, string $parameter): void
    {
        $response = $this->handle('/wtiLeads?' . $query);

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['parameter' => $parameter], $error['source'] ?? null);
    }
}
