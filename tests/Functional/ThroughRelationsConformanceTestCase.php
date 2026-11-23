<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\ThroughFixtures;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Filters and sorts declared with `->through()`, with identical assertions on both
 * providers. See {@see ThroughFixtures} for the graph; the advertiser Shadow is hidden by
 * the advertisers' scope, so leads of the autumn campaign reach no advertiser.
 */
abstract class ThroughRelationsConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * The kernel class with a static `$broken` declaration selector.
     *
     * @return class-string<\Symfony\Component\HttpKernel\Kernel>
     */
    abstract protected static function brokenKernel(): string;

    #[Test]
    public function aToOneHopComparesTheRelatedField(): void
    {
        self::assertSame(['1', '4'], $this->leadIds('filter[campaignName]=spring'));
        self::assertSame(['1', '3', '4'], $this->leadIds('filter[budget][min]=200'));
    }

    #[Test]
    public function aMultiHopPathComparesTheLastRelatedField(): void
    {
        self::assertSame(['1', '4'], $this->leadIds('filter[advertiserName]=Acme'));
        self::assertSame(['2'], $this->leadIds('filter[advertiserName]=Globex'));
    }

    #[Test]
    public function aDateRangeOverARelatedDateComparesCalendarDays(): void
    {
        self::assertSame(['1', '2', '4'], $this->leadIds('filter[campaignDates][min]=2026-03-01&filter[campaignDates][max]=2026-06-15'));
        $this->assertBadValue('filter[campaignDates][min]=2026-03-01T00:00:00Z', 'filter[campaignDates]');
    }

    #[Test]
    public function aRangeThroughAPathRefusesAValueThatIsNotAnObjectOfItsBounds(): void
    {
        $this->assertBadValue('filter[campaignDates]=2026-03-01', 'filter[campaignDates]');
        $this->assertBadValue('filter[campaignDates][after]=2026-03-01', 'filter[campaignDates]');
        $this->assertBadValue('filter[budget]=200', 'filter[budget]');
        $this->assertBadValue('filter[budget][min]=200&filter[budget][over]=100', 'filter[budget]');
        $this->assertBadValue('filter[budget][max][]=200', 'filter[budget]');

        $related = $this->handle('/thrCampaigns/' . ThroughFixtures::SPRING . '/leads?filter[budget]=200');
        self::assertSame(400, $related->getStatusCode(), (string) $related->getContent());
        $included = $this->handle('/thrCampaigns/' . ThroughFixtures::SPRING . '?include=leads&relatedQuery[leads][filter][budget]=200', 'GET', null, ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"']);
        self::assertSame(400, $included->getStatusCode(), (string) $included->getContent());
    }

    #[Test]
    public function aMalformedRangeThroughAPathIsOneErrorWithTheRangesOwnMessage(): void
    {
        $malformed = [
            'filter[campaignDates][min][]=2026-03-01' => 'filter[campaignDates][min] takes a single value.',
            'filter[campaignDates]=2026-03-01' => 'Send the bounds as filter[campaignDates][min]=<value>, filter[campaignDates][max]=<value> or both.',
            'filter[campaignDates][after]=2026-03-01' => 'filter[campaignDates] takes only the members min and max.',
        ];

        foreach ($malformed as $query => $detail) {
            $response = $this->handle('/thrLeads?' . $query);
            self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
            $errors = $this->decode($response)['errors'] ?? null;
            self::assertIsArray($errors);
            self::assertCount(1, $errors, $query . ': ' . $response->getContent());
            $error = $errors[0] ?? null;
            self::assertIsArray($error);
            self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null, $query);
            self::assertSame($detail, $error['detail'] ?? null, $query);
            self::assertSame(['parameter' => 'filter[campaignDates]'], $error['source'] ?? null, $query);
        }
    }

    #[Test]
    public function anIdFilterThroughAPathComparesTheReachedIdWithItsFormatAndEncoder(): void
    {
        self::assertSame(['1', '4'], $this->leadIds('filter[campaign]=' . ThroughFixtures::SPRING));
        self::assertSame(['2', '3', '6'], $this->leadIds('filter[notCampaign]=' . ThroughFixtures::SPRING));
        self::assertSame(['2'], $this->leadIds('filter[advertiser]=7'));
        self::assertSame(['1', '5'], $this->leadIds('filter[template]=tpl-1'));

        $this->assertBadValue('filter[campaign]=spring', 'filter[campaign]');
        $this->assertBadValue('filter[advertiser]=seven', 'filter[advertiser]');
        $this->assertBadValue('filter[template]=1', 'filter[template]');
    }

    #[Test]
    public function anIdFilterKeyedByARelationComparesItsIdsWithNoPath(): void
    {
        self::assertSame(['1', '5'], $this->leadIds('filter[templates]=tpl-1'));
        self::assertSame(['2', '3', '6'], $this->leadIds('filter[owningCampaign]=' . ThroughFixtures::SPRING));
        $this->assertBadValue('filter[templates]=1', 'filter[templates]');
    }

    #[Test]
    public function aToManyHopMatchesWhenAnyMemberMatches(): void
    {
        self::assertSame(['1', '2'], $this->leadIds('filter[templateName]=Reminder'));
        self::assertSame(['1', '4', '5'], $this->leadIds('filter[templateName][]=Welcome&filter[templateName][]=Survey'));
        // Any member outside the list, not "no member in it".
        self::assertSame(['1', '2', '4'], $this->leadIds('filter[notTemplateName]=Welcome'));
    }

    #[Test]
    public function aDeserializedListIsComparedAtTheEndOfThePath(): void
    {
        self::assertSame(['1', '2', '5'], $this->leadIds('filter[templateGroup]=onboarding'));
        self::assertSame(['1', '2', '4', '5'], $this->leadIds('filter[templateGroup][]=feedback&filter[templateGroup][]=onboarding'));
        self::assertSame(['4'], $this->leadIds('filter[notTemplateGroup]=onboarding'));
        // The enum checks the requested group, not the names it stands for.
        $this->assertBadValue('filter[templateGroup]=Welcome', 'filter[templateGroup]');
    }

    #[Test]
    public function anEmptyDeserializedListMatchesNothingAndExcludesNothing(): void
    {
        self::assertSame([], $this->leadIds('filter[templateGroup]=retired'));
        self::assertSame(['1', '2', '4', '5'], $this->leadIds('filter[notTemplateGroup]=retired'));
    }

    #[Test]
    public function aDeserializedDefaultIsInTheRequestedVocabulary(): void
    {
        self::assertSame(['4'], $this->ids('/thrAdvertisers'));
        self::assertSame(['7'], $this->ids('/thrAdvertisers?filter[season]=mid'));
        self::assertSame(['4', '7'], $this->ids('/thrAdvertisers?filter[season][]=early&filter[season][]=mid'));
        // Only the hidden advertiser runs a late campaign, and none runs one in no season.
        self::assertSame([], $this->ids('/thrAdvertisers?filter[season]=late'));
        self::assertSame([], $this->ids('/thrAdvertisers?filter[season]=none'));
    }

    #[Test]
    public function aMissingRelationNeverMatchesAThroughFilter(): void
    {
        // Katherine has no campaign: no null test through the campaign matches her.
        self::assertSame(['1', '2', '4', '6'], $this->leadIds('filter[unarchived]=1'));
        self::assertSame(['1', '2', '4', '6'], $this->leadIds('filter[archived]=false'));
        self::assertSame(['3'], $this->leadIds('filter[archived]=true'));
        self::assertSame(['5'], $this->leadIds('filter[noCampaign]=1'));
    }

    #[Test]
    public function existenceFiltersThroughAPathFindEmptyRelations(): void
    {
        self::assertSame(['1', '2', '4'], $this->leadIds('filter[advertised]=1'));
        // Winter has no advertiser and autumn's is hidden by its scope.
        self::assertSame(['3', '6'], $this->leadIds('filter[unadvertised]=1'));
    }

    #[Test]
    public function aRowTheRelatedScopeHidesDoesNotExistForMatching(): void
    {
        self::assertSame([], $this->leadIds('filter[advertiserName]=Shadow'));
        self::assertSame([], $this->leadIds('filter[advertiser]=9'));
    }

    #[Test]
    public function groupsAndFamiliesApplyEachPath(): void
    {
        self::assertSame(['4'], $this->leadIds('filter[search]=Survey'));
        self::assertSame(['1', '4'], $this->leadIds('filter[search]=Acme'));
        self::assertSame(['2'], $this->leadIds('filter[via.advertiser]=Globex'));
    }

    #[Test]
    public function theDeprecatedTraversalStillFiltersAsBefore(): void
    {
        self::assertSame(['1', '4'], $this->leadIds('filter[legacyAdvertiserName]=Acme'));
        self::assertSame(['2'], $this->leadIds('filter[legacyAdvertiserName]=Globex'));
    }

    #[Test]
    public function anOffsetPageSortsByTheReachedFieldAndNullWhereThereIsNone(): void
    {
        foreach (self::offsetSorts() as $case => [$sort, $expected]) {
            self::assertSame($expected, $this->orderedLeadIds('sort=' . $sort), $case);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function offsetSorts(): iterable
    {
        // Hedy's advertiser is hidden, Katherine has no campaign and Lin's has no advertiser.
        yield 'ascending' => ['advertiserName,name', ['3', '5', '6', '1', '4', '2']];
        yield 'descending' => ['-advertiserName,name', ['2', '1', '4', '3', '5', '6']];
        yield 'one hop' => ['campaignStart,name', ['5', '1', '4', '2', '3', '6']];
        yield 'from a family' => ['-by.budget,name', ['1', '4', '3', '2', '6', '5']];
    }

    #[Test]
    public function cursorPagesFollowTheirNextLinksThroughTheReachedField(): void
    {
        foreach (self::cursorSorts() as $case => [$sort, $expected]) {
            self::assertSame($expected, $this->walkCursor($sort), $case);
        }
    }

    /**
     * @return list<string>
     */
    private function walkCursor(string $sort): array
    {
        $ids = [];
        $next = '/thrLeads?sort=' . $sort . '&page[kind]=cursor&page[size]=2';
        $pages = 0;
        while ($next !== null) {
            self::assertLessThan(5, ++$pages, 'the walk ends');
            $response = $this->handle($next);
            self::assertSame(200, $response->getStatusCode(), $next . ': ' . $response->getContent());
            $document = $this->decode($response);
            $ids = [...$ids, ...self::idsOf($document['data'] ?? null)];
            $links = $document['links'] ?? [];
            $link = \is_array($links) ? ($links['next'] ?? null) : null;
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            $next = \is_string($href) ? (string) \preg_replace('#^https?://[^/]+#', '', $href) : null;
        }

        return $ids;
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function cursorSorts(): iterable
    {
        // A cursor page orders null as the largest value.
        yield 'ascending' => ['advertiserName,name', ['1', '4', '2', '3', '5', '6']];
        yield 'descending' => ['-advertiserName,name', ['3', '5', '6', '2', '1', '4']];
        yield 'one hop' => ['campaignStart,name', ['1', '4', '2', '3', '6', '5']];
        yield 'from a family' => ['-by.budget,name', ['5', '1', '4', '3', '2', '6']];
    }

    #[Test]
    public function aFilterDefaultReachesThroughAndARelationCanDropIt(): void
    {
        self::assertSame([ThroughFixtures::SPRING], $this->ids('/thrCampaigns'));
        self::assertSame([ThroughFixtures::SUMMER], $this->ids('/thrCampaigns?filter[advertiserName]=Globex'));
        self::assertSame([ThroughFixtures::SUMMER], $this->ids('/thrAdvertisers/7/campaigns'));
        self::assertSame([], $this->ids('/thrAdvertisers/7/defaultCampaigns'));
    }

    #[Test]
    public function aRelatedEndpointAppliesThePathsOfTheRelatedType(): void
    {
        self::assertSame(['1'], $this->ids('/thrCampaigns/' . ThroughFixtures::SPRING . '/leads?filter[templateName]=Welcome'));
        self::assertSame(['4', '1'], $this->ids('/thrCampaigns/' . ThroughFixtures::SPRING . '/leads?sort=-name'));
        self::assertSame(['1'], $this->ids('/thrCampaigns/' . ThroughFixtures::SPRING . '/leads?filter[templateGroup]=onboarding'));
    }

    #[Test]
    public function relationshipQueriesApplyAThroughFilterAndSort(): void
    {
        self::assertSame(['4'], $this->includedLeadIds('templateName]=Survey'));
        self::assertSame(['4'], $this->includedLeadIds('templateGroup]=feedback'));
        self::assertSame([], $this->includedLeadIds('templateGroup]=retired'));
    }

    /**
     * @return list<string>
     */
    private function includedLeadIds(string $filter): array
    {
        $response = $this->handle(
            '/thrCampaigns/' . ThroughFixtures::SPRING . '?include=leads&relatedQuery[leads][filter][' . $filter,
            'GET',
            null,
            ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"'],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $leads = $relationships['leads'] ?? null;
        self::assertIsArray($leads);

        return self::idsOf($leads['data'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenDeclarations(): iterable
    {
        yield 'a relation named by its storage member' => ['storageName', '"campaign" is not a relation'];
        yield 'a column naming no field' => ['noField', 'declares no such field'];
        yield 'a value filter on a relation' => ['relationLeaf', 'is a relation'];
        yield 'a sort through a to-many' => ['toManySort', 'crosses a to-many'];
        yield 'an id filter keyed by a relation with another column' => ['ambiguousId', 'could mean the relation\'s ids or the type\'s own'];
    }

    #[Test]
    public function aPathThatCannotResolveFailsTheWarmUp(): void
    {
        foreach (self::brokenDeclarations() as $case => [$declaration, $message]) {
            $this->assertWarmUpRefuses($declaration, $message, $case);
        }
    }

    private function assertWarmUpRefuses(string $declaration, string $message, string $case): void
    {
        static::ensureKernelShutdown();
        $kernelClass = static::brokenKernel();
        $kernelClass::$broken = $declaration;

        try {
            $kernel = new $kernelClass('test', false);
            (new \Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());

            try {
                KernelTestCase::bootKernel(['debug' => false]);
                self::fail($case . ': the warm-up should refuse it');
            } catch (\LogicException $e) {
                self::assertStringContainsString('type "thrBroken"', $e->getMessage(), $case);
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        } finally {
            $kernelClass::$broken = null;
            static::ensureKernelShutdown();
        }
    }

    /**
     * @return list<string>
     */
    protected function leadIds(string $query): array
    {
        $ids = $this->ids('/thrLeads?' . $query);
        \sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    protected function orderedLeadIds(string $query): array
    {
        return $this->ids('/thrLeads?' . $query);
    }

    private function assertBadValue(string $query, string $parameter): void
    {
        $response = $this->handle('/thrLeads?' . $query);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame('FILTER_VALUE_INVALID', $error['code'] ?? null);
        $source = $error['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame($parameter, $source['parameter'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function ids(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return self::idsOf($this->decode($response)['data'] ?? null);
    }

    /**
     * @return list<string>
     */
    private static function idsOf(mixed $data): array
    {
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $ids[] = \is_string($resource['id'] ?? null) ? $resource['id'] : '';
        }

        return $ids;
    }
}
