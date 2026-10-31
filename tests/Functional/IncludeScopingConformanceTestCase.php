<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\DataProvider\RelatedIncludeBatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Included resources, linkage data and counts hold exactly what the related endpoint
 * serves (bundle ADR 0035). Agency 2 ("Soft Hidden") and member 2 are hidden by the
 * kernel's scope: a Doctrine query extension, or an application provider for the
 * agency and member types. Every case runs on the `default` server and on the named
 * `partner` server, whose types the default server does not know.
 *
 * The fixture: advertiser 1 has agencies 1 and 2, advertiser 2 agencies 2 and 3;
 * agency 1 has members 1 and 2, agency 2 member 4, agency 3 member 3.
 */
abstract class IncludeScopingConformanceTestCase extends JsonApiFunctionalTestCase
{
    use IncludeScopingAssertions;

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aCollectionIncludeOfAToManyIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers?include=agencies');

        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'agencies'));
        self::assertSame(['1'], $this->linkageIds($document, '1', 'agencies'));
        self::assertSame(['3'], $this->linkageIds($document, '2', 'agencies'));
        $this->assertNoHiddenRow($document);
    }

    /**
     * A `relatedQuery` filter narrows the scoped set, never the raw association: the
     * window's rows, `?withCount` and the related endpoint's rows and total all count the
     * same filtered, scoped agencies. "Hidden" matches only the hidden agency, and "e"
     * matches every agency.
     *
     * @param list<string> $first
     * @param list<string> $second
     */
    #[Test]
    #[TestWith(['', '', 'Hidden', [], []], 'default server, a filter only a hidden row matches')]
    #[TestWith(['', '', 'e', ['1'], ['3']], 'default server, a filter every row matches')]
    #[TestWith(['/partner', 'partner-', 'Hidden', [], []], 'named server, a filter only a hidden row matches')]
    #[TestWith(['/partner', 'partner-', 'e', ['1'], ['3']], 'named server, a filter every row matches')]
    public function aFilteredWindowItsCountAndTheRelatedEndpointAgree(string $mount, string $prefix, string $name, array $first, array $second): void
    {
        $base = $mount . '/' . $prefix . 'advertisers';
        $accept = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . ' ' . RelationshipQueriesProfile::URI . '"'];
        $counts = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"'];

        $document = $this->get($base . '?include=agencies&withCount=agencies&relatedQuery[agencies][filter][nameContains]=' . $name, $accept);

        foreach (['1' => $first, '2' => $second] as $id => $expected) {
            self::assertSame($expected, $this->linkageIds($document, (string) $id, 'agencies'), 'window of ' . $id);
            self::assertSame(\count($expected), $this->value($this->relationship($document, (string) $id, 'agencies'), 'meta', 'total'), 'withCount of ' . $id);

            $related = $this->get($base . '/' . $id . '/agencies?withCount=_self_&filter[nameContains]=' . $name, $counts);
            self::assertSame($expected, $this->ids($related['data'] ?? null), 'related rows of ' . $id);
            self::assertSame(\count($expected), $this->value($related, 'meta', 'total'), 'related total of ' . $id);
        }
        $this->assertNoHiddenRow($document);
    }

    /**
     * A computed relation's closure returns every agency, the hidden one included; the
     * agency type's scope still decides which of them exist, everywhere the relation
     * renders and in every count.
     */
    /**
     * A computed to-one's closure returns each advertiser's first agency; advertiser 2's
     * is the hidden one, so it reads as none.
     */
    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aComputedToOneIsNarrowedToTheScope(string $mount, string $prefix): void
    {
        $base = $mount . '/' . $prefix . 'advertisers';

        $document = $this->get($base . '?include=firstAgency');
        self::assertSame('1', $this->toOneId($document, '1', 'firstAgency'));
        self::assertNull($this->toOneId($document, '2', 'firstAgency'));
        self::assertSame(['1'], $this->includedIds($document, $prefix . 'agencies'));

        self::assertNull($this->get($base . '/2/firstAgency')['data']);
        self::assertNull($this->get($base . '/2/relationships/firstAgency')['data']);
        self::assertSame('1', $this->value($this->get($base . '/1/firstAgency'), 'data', 'id'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aComputedRelationIsNarrowedToTheScope(string $mount, string $prefix): void
    {
        $base = $mount . '/' . $prefix . 'advertisers';
        $counts = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"'];

        $document = $this->get($base . '?include=allAgencies&withCount=allAgencies', $counts);
        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'agencies'));
        foreach (['1' => ['1'], '2' => ['3']] as $id => $expected) {
            self::assertSame($expected, $this->linkageIds($document, (string) $id, 'allAgencies'), 'linkage of ' . $id);
            self::assertSame(\count($expected), $this->value($this->relationship($document, (string) $id, 'allAgencies'), 'meta', 'total'), 'withCount of ' . $id);

            $related = $this->get($base . '/' . $id . '/allAgencies?withCount=_self_&page[number]=1&page[size]=1', $counts);
            self::assertSame($expected, $this->ids($related['data'] ?? null), 'related page of ' . $id);
            self::assertSame(\count($expected), $this->value($related, 'meta', 'total'), 'related total of ' . $id);
            self::assertSame($expected, $this->ids($this->get($base . '/' . $id . '/relationships/allAgencies')['data'] ?? null), 'relationship of ' . $id);
        }
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aRelationPermissionNeverBypassesTheScope(string $mount, string $prefix): void
    {
        $base = $mount . '/' . $prefix . 'advertisers';
        $counts = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"'];

        $document = $this->get($base . '?include=openAgencies&withCount=openAgencies', $counts);
        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'agencies'));
        self::assertSame(['1'], $this->linkageIds($document, '1', 'openAgencies'));
        self::assertSame(1, $this->value($this->relationship($document, '1', 'openAgencies'), 'meta', 'total'));
        $this->assertNoHiddenRow($document);

        $related = $this->get($base . '/1/openAgencies?withCount=_self_', $counts);
        self::assertSame(['1'], $this->ids($related['data'] ?? null));
        self::assertSame(1, $this->value($related, 'meta', 'total'));
        self::assertSame(['1'], $this->ids($this->get($base . '/1/relationships/openAgencies')['data'] ?? null));
    }

    /**
     * On the related endpoint, each related agency's members follow a `relatedQuery`
     * filter and the scope exactly as they do on a primary document: the linkage, the
     * included members and the count come from the same set. Agency 1 holds member 1 and
     * the hidden member 2; "One" matches member 1, "Three" matches neither.
     *
     * @param list<string> $expected
     */
    #[Test]
    #[TestWith(['', '', 'One', ['1']], 'default server, a filter a visible member matches')]
    #[TestWith(['', '', 'Three', []], 'default server, a filter no member of agency 1 matches')]
    #[TestWith(['', '', 'Hidden', []], 'default server, a filter only the hidden member matches')]
    #[TestWith(['/partner', 'partner-', 'One', ['1']], 'named server, a filter a visible member matches')]
    #[TestWith(['/partner', 'partner-', 'Hidden', []], 'named server, a filter only the hidden member matches')]
    public function aRelatedEndpointsItemsFollowARelatedQueryLikeAPrimaryDocument(string $mount, string $prefix, string $name, array $expected): void
    {
        $accept = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . ' ' . RelationshipQueriesProfile::URI . '"'];
        $query = '?include=members&withCount=members&relatedQuery[members][filter][nameContains]=' . $name;

        $primary = $this->get($mount . '/' . $prefix . 'agencies/1' . $query, $accept);
        $related = $this->get($mount . '/' . $prefix . 'advertisers/1/agencies' . $query, $accept);

        foreach (['primary' => $primary, 'related' => $related] as $label => $document) {
            self::assertSame($expected, $this->linkageIds($document, '1', 'members'), $label . ' linkage');
            self::assertSame(\count($expected), $this->value($this->relationship($document, '1', 'members'), 'meta', 'total'), $label . ' count');
            self::assertSame($expected, $this->includedIds($document, $prefix . 'members'), $label . ' included');
            $this->assertNoHiddenRow($document);
        }
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aSingleResourceIncludeOfAToManyIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers/1?include=agencies');

        self::assertSame(['1'], $this->includedIds($document, $prefix . 'agencies'));
        self::assertSame(['1'], $this->linkageIds($document, '1', 'agencies'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aNestedIncludeIsScopedAtEveryLevel(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers?include=agencies.members');

        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'agencies'));
        // Member 2 is hidden, and member 4 belongs only to the hidden agency.
        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'members'));
        self::assertSame(['1'], $this->includedLinkageIds($document, $prefix . 'agencies', '1', 'members'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function alwaysEmittedToManyLinkageWithoutAnIncludeIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers');

        self::assertSame(['1'], $this->linkageIds($document, '1', 'listedAgencies'));
        self::assertSame(['3'], $this->linkageIds($document, '2', 'listedAgencies'));
        self::assertArrayNotHasKey('included', $document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function withCountCountsTheScopedSet(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers?withCount=agencies', [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"',
        ]);

        self::assertSame(1, $this->value($this->relationship($document, '1', 'agencies'), 'meta', 'total'));
        self::assertSame(1, $this->value($this->relationship($document, '2', 'agencies'), 'meta', 'total'));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function theToManyRelatedAndRelationshipEndpointsAreScoped(string $mount, string $prefix): void
    {
        $base = $mount . '/' . $prefix . 'advertisers/1';

        self::assertSame(['1'], $this->ids($this->get($base . '/agencies')['data']));
        self::assertSame(['1'], $this->ids($this->get($base . '/relationships/agencies')['data']));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function theToOneRelatedEndpointIsScoped(string $mount, string $prefix): void
    {
        self::assertNull($this->get($mount . '/' . $prefix . 'members/4/agency')['data']);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function anIncludedToOneWhoseTargetIsHiddenRendersNull(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'members?include=agency');

        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'agencies'));
        self::assertNull($this->toOneId($document, '4', 'agency'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function toOneLinkageWithoutAnIncludeIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'members');

        self::assertArrayHasKey('data', $this->relationship($document, '4', 'agency'));
        self::assertNull($this->toOneId($document, '4', 'agency'));
        self::assertSame('1', $this->toOneId($document, '1', 'agency'));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aFlattenedAttributeReadsTheScopedTarget(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'members');

        self::assertNull($this->value($this->resource($document, '4'), 'attributes', 'agencyName'));
        self::assertSame('Visible One', $this->value($this->resource($document, '1'), 'attributes', 'agencyName'));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aPolymorphicToOneIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'members?include=party');

        self::assertNull($this->toOneId($document, '4', 'party'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function anIncludedHasOneWhoseTargetIsHiddenRendersNull(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'agencies?include=lead');

        self::assertNull($this->toOneId($document, '1', 'lead'));
        self::assertSame('3', $this->toOneId($document, '3', 'lead'));
        self::assertSame(['3'], $this->includedIds($document, $prefix . 'members'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function hasOneLinkageWithoutAnIncludeIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'agencies/1');

        self::assertArrayHasKey('data', $this->relationship($document, '1', 'lead'));
        self::assertNull($this->toOneId($document, '1', 'lead'));
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aFlattenedAttributeCannotBeWrittenThroughAHiddenTarget(string $mount, string $prefix): void
    {
        $response = $this->handle($mount . '/' . $prefix . 'members/4', 'PATCH', [
            'data' => ['type' => $prefix . 'members', 'id' => '4', 'attributes' => ['agencyName' => 'Renamed']],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('RELATED_ATTRIBUTE_OWNER_MISSING', (string) $response->getContent());
        self::assertSame('Soft Hidden', $this->hiddenAgencyName());
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function theToOneRelatedEndpointIsScopedForAPolymorphicToOne(string $mount, string $prefix): void
    {
        self::assertNull($this->get($mount . '/' . $prefix . 'members/4/party')['data']);
        self::assertNull($this->get($mount . '/' . $prefix . 'agencies/1/lead')['data']);
    }

    /**
     * The stored name of the hidden agency, to prove a refused write left it alone.
     */
    abstract protected function hiddenAgencyName(): string;

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function theToOneRelationshipEndpointIsScoped(string $mount, string $prefix): void
    {
        self::assertNull($this->get($mount . '/' . $prefix . 'members/4/relationships/agency')['data']);
        self::assertNull($this->get($mount . '/' . $prefix . 'members/4/relationships/party')['data']);
        self::assertNull($this->get($mount . '/' . $prefix . 'agencies/1/relationships/lead')['data']);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function anIncludeOnTheRelatedEndpointIsScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers/1/agencies?include=members');

        self::assertSame(['1'], $this->ids($document['data']));
        self::assertSame(['1'], $this->includedIds($document, $prefix . 'members'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function theRelationshipQueriesWindowAndTheIncludesUnderItAreScoped(string $mount, string $prefix): void
    {
        $document = $this->get(
            $mount . '/' . $prefix . 'advertisers?include=agencies.members&relatedQuery[agencies][sort]=-name',
            ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"'],
        );

        self::assertSame(['1'], $this->linkageIds($document, '1', 'agencies'));
        self::assertSame(['3'], $this->linkageIds($document, '2', 'agencies'));
        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'agencies'));
        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'members'));
        self::assertSame(['1'], $this->includedLinkageIds($document, $prefix . 'agencies', '1', 'members'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function aComputedRelationRendersItsClosureAndItsTargetsAreScoped(string $mount, string $prefix): void
    {
        $document = $this->get($mount . '/' . $prefix . 'advertisers?include=curatedAgencies.members');

        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'agencies'));
        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'members'));
        $this->assertNoHiddenRow($document);
    }

    #[Test]
    #[TestWith(['', ''], 'default server')]
    #[TestWith(['/partner', 'partner-'], 'named server')]
    public function includesStayScopedWhenBatchingIsDisabled(string $mount, string $prefix): void
    {
        $batcher = static::getContainer()->get('test.scoping_include_batcher');
        self::assertInstanceOf(RelatedIncludeBatcher::class, $batcher);

        $batcher->disable();
        try {
            $document = $this->get($mount . '/' . $prefix . 'advertisers?include=agencies.members');
        } finally {
            $batcher->enable();
        }

        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'agencies'));
        self::assertSame(['1', '3'], $this->includedIds($document, $prefix . 'members'));
        self::assertSame(['1'], $this->linkageIds($document, '1', 'agencies'));
        $this->assertNoHiddenRow($document);
    }
}
