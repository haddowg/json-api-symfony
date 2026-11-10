<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\CountThroughFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Relationship filters on a counted relation, asserted identically against the
 * in-memory provider ({@see InMemoryRelationCountThroughTest}) and the Doctrine provider
 * ({@see DoctrineRelationCountThroughTest}). A `WhereThrough`, `WhereThroughIn`, `WhereHas`
 * or `WhereDoesntHave` on a campaign's leads or shortlist tests each lead, so a
 * `?withCount` total and a Relationship Queries window total count the leads it keeps.
 * Campaigns and leads both have a `brand`, so a filter tested on the campaign instead
 * counts the wrong leads. The graph is {@see CountThroughFixtures}.
 */
#[Group('spec:fetching-relationships')]
#[Group('spec:fetching-filtering')]
abstract class RelationCountThroughConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . ' ' . RelationshipQueriesProfile::URI . '"';

    /**
     * Each case's relation, `relatedQuery` filter and expected total per campaign.
     *
     * @return iterable<string, array{string, string, array<int, int>}>
     */
    private static function filteredCounts(): iterable
    {
        yield 'a relation-scoped WhereThrough on an inverse foreign key' => ['leads', 'leadBrand]=Acme', [1 => 1, 2 => 0, 3 => 1]];
        yield 'a requested WhereThrough on an inverse foreign key' => ['leads', 'brandName]=Globex', [1 => 1, 2 => 1, 3 => 0]];
        yield 'a WhereThrough whose first hop only the lead has' => ['leads', 'templateName]=Welcome', [1 => 2, 2 => 0, 3 => 1]];
        yield 'a WhereHas on an inverse foreign key' => ['leads', 'branded]=1', [1 => 2, 2 => 1, 3 => 1]];
        yield 'a WhereDoesntHave on an inverse foreign key' => ['leads', 'unbranded]=1', [1 => 1, 2 => 0, 3 => 1]];
        yield 'a relation-scoped WhereThrough on a join table' => ['shortlist', 'leadBrand]=Acme', [1 => 2, 2 => 0, 3 => 0]];
        yield 'a requested WhereThrough on a join table' => ['shortlist', 'templateName]=Reminder', [1 => 2, 2 => 0, 3 => 0]];
        yield 'a WhereHas on a join table' => ['shortlist', 'branded]=1', [1 => 3, 2 => 1, 3 => 0]];
        yield 'a relation-scoped WhereThroughIn on an inverse foreign key' => ['leads', 'usesTemplate]=1,2', [1 => 2, 2 => 1, 3 => 1]];
        yield 'a relation-scoped WhereThroughIn on a join table' => ['shortlist', 'usesTemplate]=1', [1 => 2, 2 => 1, 3 => 0]];
        yield 'a requested WhereThroughIn ending on the shared relationship' => ['leads', 'brandIn]=1', [1 => 1, 2 => 0, 3 => 1]];
        yield 'a requested WhereThroughIn on a join table' => ['shortlist', 'brandIn]=1,2', [1 => 3, 2 => 1, 3 => 0]];
        yield 'a WhereThrough ending on the shared relationship' => ['leads', 'brandIs]=2', [1 => 1, 2 => 1, 3 => 0]];
        yield 'a relationship-ending WhereThrough on a join table' => ['shortlist', 'brandIs]=2', [1 => 1, 2 => 1, 3 => 0]];
    }

    #[Test]
    public function aWithCountCountsTheMembersTheFilterKeeps(): void
    {
        foreach (self::filteredCounts() as $case => [$relation, $filter, $expected]) {
            $document = $this->fetch(\sprintf('/ctCampaigns?withCount=%1$s&relatedQuery[%1$s][filter][%2$s', $relation, $filter), $case);

            self::assertSame($expected, $this->totals($document, $relation), $case);
        }
    }

    #[Test]
    public function aWindowedIncludeTotalsTheMembersTheFilterKeeps(): void
    {
        foreach (self::filteredCounts() as $case => [$relation, $filter, $expected]) {
            $document = $this->fetch(\sprintf('/ctCampaigns?include=%1$s&withCount=%1$s&relatedQuery[%1$s][filter][%2$s', $relation, $filter), $case);

            self::assertSame($expected, $this->totals($document, $relation), $case);
            self::assertSame($expected, \array_map(\count(...), $this->linkage($document, $relation)), $case);
        }
    }

    #[Test]
    public function aSingleCampaignCountsTheMembersTheFilterKeeps(): void
    {
        $document = $this->fetch('/ctCampaigns/1?withCount=leads&relatedQuery[leads][filter][brandName]=Acme');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame([1 => 1], $this->totals(['data' => [$data]], 'leads'));
    }

    #[Test]
    public function theCountMatchesTheRelatedEndpointsTotal(): void
    {
        $document = $this->fetch('/ctCampaigns/1/leads?filter[brandName]=Globex');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(1, $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path, string $case = ''): array
    {
        $response = $this->handle('https://example.test' . $path, extraServer: ['HTTP_ACCEPT' => self::ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), $case . ' ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * Each campaign's `meta.total` for `$relation`, keyed by campaign id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<int, int>
     */
    private function totals(array $document, string $relation): array
    {
        $totals = [];
        foreach ($this->relationshipsOf($document, $relation) as $id => $relationship) {
            $meta = $relationship['meta'] ?? null;
            self::assertIsArray($meta, \sprintf('campaign %d %s carries meta', $id, $relation));
            $total = $meta['total'] ?? null;
            self::assertIsInt($total);
            $totals[$id] = $total;
        }

        return $totals;
    }

    /**
     * Each campaign's linkage ids for `$relation`, keyed by campaign id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<int, list<string>>
     */
    private function linkage(array $document, string $relation): array
    {
        $linkage = [];
        foreach ($this->relationshipsOf($document, $relation) as $id => $relationship) {
            $data = $relationship['data'] ?? null;
            self::assertIsArray($data);
            $linkage[$id] = [];
            foreach ($data as $identifier) {
                self::assertIsArray($identifier);
                $member = $identifier['id'] ?? null;
                self::assertIsString($member);
                $linkage[$id][] = $member;
            }
        }

        return $linkage;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<int, array<string, mixed>>
     */
    private function relationshipsOf(array $document, string $relation): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $byCampaign = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $relationships = $resource['relationships'] ?? null;
            self::assertIsArray($relationships);
            $relationship = $relationships[$relation] ?? null;
            self::assertIsArray($relationship);
            /** @var array<string, mixed> $relationship */
            $byCampaign[(int) $id] = $relationship;
        }
        \ksort($byCampaign);

        return $byCampaign;
    }
}
