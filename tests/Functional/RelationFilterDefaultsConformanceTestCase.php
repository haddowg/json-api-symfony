<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A relation that drops its related type's filter defaults with
 * `withoutFilterDefaults()` serves the same members on every read. The tracks type
 * hides explicit and archived tracks by default. Album 1 holds a clean track (1), an
 * explicit one (2), an archived one (3) and one both explicit and archived (4); album 2
 * holds a clean track (5). `tracks` keeps both defaults, `explicitTracks` drops the
 * `explicit` one, `allTracks` drops both, and `cleanTracks` drops both but declares
 * its own `explicit` default.
 */
abstract class RelationFilterDefaultsConformanceTestCase extends JsonApiFunctionalTestCase
{
    use IncludeScopingAssertions;

    private const string BASE_URI = 'https://example.test';

    /**
     * Album 1's members under each relation.
     */
    private const array MEMBERS = [
        'tracks' => ['1'],
        'explicitTracks' => ['1', '2'],
        'allTracks' => ['1', '2', '3', '4'],
        'cleanTracks' => ['1', '3'],
    ];

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theLinkageListsTheMembersEachRelationKeeps(): void
    {
        $document = $this->get(self::BASE_URI . '/albums/1');

        foreach (self::MEMBERS as $name => $members) {
            self::assertSame($members, $this->linkageIds($document, '1', $name), $name);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function eachParentOfACollectionListsTheMembersEachRelationKeeps(): void
    {
        $document = $this->get(self::BASE_URI . '/albums');

        foreach (self::MEMBERS as $name => $members) {
            self::assertSame($members, $this->linkageIds($document, '1', $name), $name);
            self::assertSame(['5'], $this->linkageIds($document, '2', $name), $name);
        }
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludedRelationIncludesTheMembersItKeeps(): void
    {
        foreach (self::MEMBERS as $name => $members) {
            $document = $this->get(self::BASE_URI . '/albums/1?include=' . $name);

            self::assertSame($members, $this->linkageIds($document, '1', $name), $name);
            self::assertSame($members, $this->includedIds($document, 'tracks'), $name);
        }

        $document = $this->get(self::BASE_URI . '/albums?include=allTracks');
        self::assertSame(['1', '2', '3', '4', '5'], $this->includedIds($document, 'tracks'));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function theCountCountsTheMembersEachRelationKeeps(): void
    {
        $document = $this->get(
            self::BASE_URI . '/albums/1?withCount=' . \implode(',', \array_keys(self::MEMBERS)),
            ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"'],
        );

        foreach (self::MEMBERS as $name => $members) {
            self::assertSame(\count($members), $this->value($this->relationship($document, '1', $name), 'meta', 'total'), $name);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelatedAndRelationshipEndpointsServeTheMembersEachRelationKeeps(): void
    {
        foreach (self::MEMBERS as $name => $members) {
            self::assertSame($members, $this->ids($this->get(self::BASE_URI . '/albums/1/' . $name)['data'] ?? null), $name);
            self::assertSame($members, $this->ids($this->get(self::BASE_URI . '/albums/1/relationships/' . $name)['data'] ?? null), $name);
        }
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aRelationshipQueriesWindowServesTheMembersEachRelationKeeps(): void
    {
        $accept = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"'];

        foreach (self::MEMBERS as $name => $members) {
            $document = $this->get(self::BASE_URI . '/albums/1?include=' . $name . '&relatedQuery[' . $name . '][sort]=-title', $accept);

            $data = $this->value($this->relationship($document, '1', $name), 'data');
            self::assertIsArray($data, $name);
            self::assertSame(\array_reverse($members), \array_map(static fn(mixed $item): mixed => \is_array($item) ? ($item['id'] ?? null) : null, $data), $name);
        }
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aFilterTheClientSendsStillAppliesWhereTheDefaultIsDropped(): void
    {
        self::assertSame(['2', '4'], $this->ids($this->get(self::BASE_URI . '/albums/1/allTracks?filter[explicit]=true')['data'] ?? null));
        self::assertSame(['3', '4'], $this->ids($this->get(self::BASE_URI . '/albums/1/relationships/allTracks?filter[archived]=true')['data'] ?? null));
        // The archived default still applies on a relation that drops only `explicit`.
        self::assertSame(['2'], $this->ids($this->get(self::BASE_URI . '/albums/1/explicitTracks?filter[explicit]=true')['data'] ?? null));
        // A request overrides the relation's own default as it would the type's.
        self::assertSame(['2', '4'], $this->ids($this->get(self::BASE_URI . '/albums/1/cleanTracks?filter[explicit]=true')['data'] ?? null));

        $document = $this->get(
            self::BASE_URI . '/albums/1?include=allTracks&relatedQuery[allTracks][filter][explicit]=false',
            ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"'],
        );
        self::assertSame(['1', '3'], $this->linkageIds($document, '1', 'allTracks'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function theRelatedTypesOwnCollectionKeepsItsDefaults(): void
    {
        self::assertSame(['1', '5'], $this->ids($this->get(self::BASE_URI . '/tracks')['data'] ?? null));
        self::assertSame(['2'], $this->ids($this->get(self::BASE_URI . '/tracks?filter[explicit]=true')['data'] ?? null));
    }
}
