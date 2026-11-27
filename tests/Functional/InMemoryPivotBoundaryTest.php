<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotBoundaryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The in-memory pivot boundary witness: the in-memory provider keeps pivot rows beside each
 * parent but doesn't query them. So on the `playlists.tracks` related endpoint a pivot
 * `?filter`/`?sort` key is unrecognised (400) while the track's OWN `?filter[title]` works,
 * and every member renders its stored `meta.pivot`, on the related and relationship
 * endpoints and on a primary document's default-rendered linkage alike. A relationship
 * write stores the pivot fields each member sends.
 */
final class InMemoryPivotBoundaryTest extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return PivotBoundaryTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aPivotFilterIsUnrecognisedOnTheInMemoryRelatedEndpoint(): void
    {
        $response = $this->handle(self::BASE_URI . '/playlists/1/tracks?filter[position]=1');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function aFilterDefaultWithAPivotAfterARelationIsUnrecognisedOnEveryRead(): void
    {
        foreach (['/setlists', '/setlists?filter[firstSlot]=2'] as $uri) {
            $response = $this->handle(self::BASE_URI . $uri);
            self::assertSame(400, $response->getStatusCode(), $uri . ' ' . $response->getContent());
            self::assertStringContainsString('filter[firstSlot]', (string) $response->getContent(), $uri);
        }

        self::assertSame(200, $this->handle(self::BASE_URI . '/setlists/1')->getStatusCode());
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function everyPivotFilterIsUnrecognisedOnEveryInMemoryPath(): void
    {
        $queries = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '",application/vnd.api+json;profile="' . CountableProfile::URI . '"';
        foreach (['position' => '1', 'untagged' => '1', 'tagName' => 'chill', 'pivotPosition' => '1'] as $key => $value) {
            foreach ([
                '/playlists/1/tracks?filter[' . $key . ']=' . $value,
                '/playlists/1/relationships/tracks?filter[' . $key . ']=' . $value,
                '/playlists?include=tracks&relatedQuery[tracks][filter][' . $key . ']=' . $value,
                '/playlists?withCount=tracks&relatedQuery[tracks][filter][' . $key . ']=' . $value,
            ] as $uri) {
                $response = $this->handle(self::BASE_URI . $uri, 'GET', null, ['HTTP_ACCEPT' => $queries]);
                self::assertSame(400, $response->getStatusCode(), $uri . ' ' . $response->getContent());
                self::assertStringContainsString('FILTERING_UNRECOGNIZED', (string) $response->getContent(), $uri);
            }
        }
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aPivotSortIsUnrecognisedOnTheInMemoryRelatedEndpoint(): void
    {
        $response = $this->handle(self::BASE_URI . '/playlists/1/tracks?sort=position');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());

        $throughPivot = $this->handle(self::BASE_URI . '/playlists/1/tracks?sort=pivotPosition');
        self::assertSame(400, $throughPivot->getStatusCode(), (string) $throughPivot->getContent());
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theInMemoryRelatedEndpointRendersEachMembersStoredPivot(): void
    {
        // The related collection itself works in memory: the track's own filter narrows
        // it, and each member renders the position its pivot row holds.
        $response = $this->handle(self::BASE_URI . '/playlists/1/tracks?filter[title]=o');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1' => ['position' => 1], '2' => ['position' => 2]], $this->pivots($this->decode($response)['data'] ?? null));
        self::assertSame(['1' => ['position' => 1], '2' => ['position' => 2]], $this->pivots($this->decode($this->handle(self::BASE_URI . '/playlists/1/relationships/tracks'))['data'] ?? null));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPrimaryDocumentDefaultLinkageCarriesItsStoredPivotInMemory(): void
    {
        // `dataTracks` renders its linkage data by default (withData()) over the same rows.
        $response = $this->handle(self::BASE_URI . '/playlists/1');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $dataTracks = $relationships['dataTracks'] ?? null;
        self::assertIsArray($dataTracks);
        self::assertSame(['1' => ['position' => 1], '2' => ['position' => 2]], $this->pivots($dataTracks['data'] ?? null));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aRelationshipWriteStoresThePivotFieldsEachMemberSends(): void
    {
        $add = $this->handle(self::BASE_URI . '/playlists/1/relationships/dataTracks', 'PATCH', ['data' => [
            ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 5]]],
            ['type' => 'tracks', 'id' => '1'],
        ]]);
        self::assertSame(200, $add->getStatusCode(), (string) $add->getContent());
        self::assertSame(['1' => ['position' => 1], '2' => ['position' => 5]], $this->pivots($this->decode($add)['data'] ?? null));

        $removed = $this->handle(self::BASE_URI . '/playlists/1/relationships/dataTracks', 'DELETE', ['data' => [['type' => 'tracks', 'id' => '1']]]);
        self::assertSame(200, $removed->getStatusCode(), (string) $removed->getContent());
        self::assertSame(['2' => ['position' => 5]], $this->pivots($this->decode($this->handle(self::BASE_URI . '/playlists/1/relationships/tracks'))['data'] ?? null));
    }

    /**
     * Each member's `meta.pivot`, keyed by id in id order.
     *
     * @return array<string, mixed>
     */
    private function pivots(mixed $members): array
    {
        self::assertIsArray($members);
        $pivots = [];
        foreach ($members as $member) {
            self::assertIsArray($member);
            self::assertIsString($member['id'] ?? null);
            $meta = $member['meta'] ?? null;
            self::assertIsArray($meta, (string) \json_encode($member));
            $pivots[$member['id']] = $meta['pivot'] ?? null;
        }
        \ksort($pivots);

        return $pivots;
    }
}
