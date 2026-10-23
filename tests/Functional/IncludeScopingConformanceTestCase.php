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
 * serves (bundle ADR 0125). Agency 2 ("Soft Hidden") and member 2 are hidden by the
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
