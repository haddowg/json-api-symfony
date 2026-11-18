<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A relation declared with a foreign key reads the related rows whose foreign key holds
 * the parent's id, on every read path, whatever serves the parent: `fk-users` and the
 * `/inbox` singleton come from a custom provider holding none of their rows, and
 * `fk-teams` is an entity with no mapping to its members. Asserted identically on the
 * in-memory ({@see InMemoryForeignKeyTest}) and Doctrine ({@see DoctrineForeignKeyTest})
 * kernels over {@see \haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\ForeignKeySeed}.
 */
#[Group('spec:fetching-relationships')]
#[Group('spec:fetching-includes')]
abstract class ForeignKeyConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected const string BASE_URI = 'https://example.test';

    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    private const string QUERIES_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    #[Test]
    public function aPlainReadOfAProviderBackedParentRenders(): void
    {
        $document = $this->fetch('/fk-users/ada');

        self::assertSame('Ada', $this->attribute($document['data'] ?? null, 'name'));
        self::assertSame(['1', '2'], $this->ids($this->relationship($document['data'] ?? null, 'featuredItems')['data'] ?? null));
    }

    #[Test]
    public function theRelatedEndpointServesTheRowsHoldingTheParentsKey(): void
    {
        $document = $this->fetch('/fk-users/ada/items');

        self::assertSame(['1', '2'], $this->ids($document['data'] ?? null));
        self::assertSame(self::BASE_URI . '/fk-users/ada/items?page%5Bnumber%5D=2&page%5Bsize%5D=2', $this->links($document)['next'] ?? null);
        self::assertSame([], $this->ids($this->fetch('/fk-users/cy/items')['data'] ?? null));
    }

    #[Test]
    public function anOffsetPagePastTheFirstServesTheNextRows(): void
    {
        self::assertSame(['3', '4'], $this->ids($this->fetch('/fk-users/ada/items?page[number]=2&page[size]=2')['data'] ?? null));
        self::assertSame(['5'], $this->ids($this->fetch('/fk-users/ada/items?page[number]=3&page[size]=2')['data'] ?? null));
    }

    #[Test]
    public function theRelatedEndpointFiltersAndSortsTheRows(): void
    {
        self::assertSame(['3'], $this->ids($this->fetch('/fk-users/ada/items?filter[title]=Three')['data'] ?? null));
        self::assertSame(['2', '3'], $this->ids($this->fetch('/fk-users/ada/items?sort=-title')['data'] ?? null));
    }

    #[Test]
    public function aCountedRelatedPageCarriesItsTotal(): void
    {
        $document = $this->fetch('/fk-users/ada/items?withCount=_self_', self::COUNTS_ACCEPT);

        self::assertSame(['1', '2'], $this->ids($document['data'] ?? null));
        self::assertSame(5, $this->meta($document)['total'] ?? null);
    }

    #[Test]
    public function theRelationshipEndpointServesTheSameRows(): void
    {
        $document = $this->fetch('/fk-users/ada/relationships/items');

        self::assertSame(['1', '2'], $this->ids($document['data'] ?? null));
        self::assertSame(['6'], $this->ids($this->fetch('/fk-users/bob/relationships/items')['data'] ?? null));
    }

    #[Test]
    public function anIncludeRendersTheFirstPage(): void
    {
        $document = $this->fetch('/fk-users/ada?include=items');
        $items = $this->relationship($document['data'] ?? null, 'items');

        self::assertSame(['1', '2'], $this->ids($items['data'] ?? null));
        self::assertSame(['fk-items:1', 'fk-items:2'], $this->includedKeys($document));
        self::assertSame(self::BASE_URI . '/fk-users/ada/relationships/items?page%5Bnumber%5D=2&page%5Bsize%5D=2', $this->links($items)['next'] ?? null);
    }

    #[Test]
    public function aCollectionIncludeCutsEachParentsFirstPage(): void
    {
        $document = $this->fetch('/fk-users?include=items');
        $users = $document['data'] ?? null;
        self::assertIsArray($users);

        self::assertSame(['1', '2'], $this->ids($this->relationship($users[0] ?? null, 'items')['data'] ?? null));
        self::assertSame(['6'], $this->ids($this->relationship($users[1] ?? null, 'items')['data'] ?? null));
        self::assertSame([], $this->ids($this->relationship($users[2] ?? null, 'items')['data'] ?? null));
        self::assertSame(['fk-items:1', 'fk-items:2', 'fk-items:6'], $this->includedKeys($document));
    }

    #[Test]
    public function withDataLinkageRendersTheFirstPageOnAPlainRead(): void
    {
        $document = $this->fetch('/fk-users');
        $users = $document['data'] ?? null;
        self::assertIsArray($users);

        self::assertSame(['1', '2'], $this->ids($this->relationship($users[0] ?? null, 'featuredItems')['data'] ?? null));
        self::assertSame(['6'], $this->ids($this->relationship($users[1] ?? null, 'featuredItems')['data'] ?? null));
        self::assertArrayNotHasKey('included', $document);
    }

    #[Test]
    public function withCountTotalsEachParentsRows(): void
    {
        $document = $this->fetch('/fk-users?withCount=items', self::COUNTS_ACCEPT);
        $users = $document['data'] ?? null;
        self::assertIsArray($users);

        self::assertSame(['total' => 5], $this->relationship($users[0] ?? null, 'items')['meta'] ?? null);
        self::assertSame(['total' => 1], $this->relationship($users[1] ?? null, 'items')['meta'] ?? null);
        self::assertSame(['total' => 0], $this->relationship($users[2] ?? null, 'items')['meta'] ?? null);
    }

    #[Test]
    public function aCursorPageAndItsNextAreBoundedByTheForeignKey(): void
    {
        $first = $this->fetch('/fk-users/ada/cursorItems');
        self::assertSame(['1', '2'], $this->ids($first['data'] ?? null));

        $next = $this->links($first)['next'] ?? null;
        self::assertIsString($next);
        self::assertSame(['3', '4'], $this->ids($this->fetch(\substr($next, \strlen(self::BASE_URI)))['data'] ?? null));

        $included = $this->fetch('/fk-users?include=cursorItems');
        $users = $included['data'] ?? null;
        self::assertIsArray($users);
        self::assertSame(['1', '2'], $this->ids($this->relationship($users[0] ?? null, 'cursorItems')['data'] ?? null));
        self::assertSame(['6'], $this->ids($this->relationship($users[1] ?? null, 'cursorItems')['data'] ?? null));
    }

    #[Test]
    public function aRelatedQueryReordersAndNarrowsTheIncludedPage(): void
    {
        $sorted = $this->fetch('/fk-users/ada?include=items&relatedQuery[items][sort]=-title', self::QUERIES_ACCEPT);
        self::assertSame(['2', '3'], $this->ids($this->relationship($sorted['data'] ?? null, 'items')['data'] ?? null));

        $filtered = $this->fetch('/fk-users/ada?include=items&relatedQuery[items][filter][title]=Four', self::QUERIES_ACCEPT);
        self::assertSame(['4'], $this->ids($this->relationship($filtered['data'] ?? null, 'items')['data'] ?? null));
    }

    #[Test]
    public function theRelatedTypesFilterDefaultsApplyOnEveryPath(): void
    {
        self::assertSame(['1'], $this->ids($this->fetch('/fk-users/ada/notes')['data'] ?? null));
        self::assertSame(['1'], $this->ids($this->fetch('/fk-users/ada/relationships/notes')['data'] ?? null));
        self::assertSame(['2'], $this->ids($this->fetch('/fk-users/ada/notes?filter[archived]=true')['data'] ?? null));

        $included = $this->fetch('/fk-users?include=notes');
        $users = $included['data'] ?? null;
        self::assertIsArray($users);
        self::assertSame(['1'], $this->ids($this->relationship($users[0] ?? null, 'notes')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->relationship($users[1] ?? null, 'notes')['data'] ?? null));
        self::assertSame(['fk-notes:1', 'fk-notes:3'], $this->includedKeys($included));

        $counted = $this->fetch('/fk-users/ada?withCount=notes', self::COUNTS_ACCEPT);
        self::assertSame(['total' => 1], $this->relationship($counted['data'] ?? null, 'notes')['meta'] ?? null);
    }

    #[Test]
    public function aHasOneReadsItsTargetThroughTheForeignKey(): void
    {
        $profile = $this->fetch('/fk-users/ada/profile');
        self::assertSame('1', \is_array($profile['data'] ?? null) ? ($profile['data']['id'] ?? null) : null);
        $none = $this->fetch('/fk-users/bob/profile');
        self::assertArrayHasKey('data', $none);
        self::assertNull($none['data']);

        $linkage = $this->fetch('/fk-users/ada/relationships/profile');
        self::assertSame(['type' => 'fk-profiles', 'id' => '1'], $linkage['data'] ?? null);

        $included = $this->fetch('/fk-users?include=profile');
        $users = $included['data'] ?? null;
        self::assertIsArray($users);
        self::assertSame(['type' => 'fk-profiles', 'id' => '1'], $this->relationship($users[0] ?? null, 'profile')['data'] ?? null);
        $bob = $this->relationship($users[1] ?? null, 'profile');
        self::assertArrayHasKey('data', $bob);
        self::assertNull($bob['data']);
        self::assertSame(['fk-profiles:1'], $this->includedKeys($included));
    }

    #[Test]
    public function aProviderBackedSingletonReadsThroughTheForeignKey(): void
    {
        $document = $this->fetch('/inbox?include=items');
        self::assertSame(['1', '2'], $this->ids($this->relationship($document['data'] ?? null, 'items')['data'] ?? null));

        self::assertSame(['1', '2'], $this->ids($this->fetch('/inbox/items')['data'] ?? null));
        self::assertSame(['1', '2'], $this->ids($this->fetch('/inbox/relationships/items')['data'] ?? null));
    }

    #[Test]
    public function anEntityParentWithNoInverseMappingReadsThroughTheForeignKey(): void
    {
        self::assertSame(['1', '2'], $this->ids($this->fetch('/fk-teams/1/members')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->fetch('/fk-teams/1/members?page[number]=2&page[size]=2')['data'] ?? null));
        self::assertSame(['4'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));

        $document = $this->fetch('/fk-teams?include=members&withCount=members', self::COUNTS_ACCEPT);
        $teams = $document['data'] ?? null;
        self::assertIsArray($teams);
        $red = $this->relationship($teams[0] ?? null, 'members');
        self::assertSame(['1', '2'], $this->ids($red['data'] ?? null));
        self::assertSame(['total' => 3], $red['meta'] ?? null);
        self::assertSame(['4'], $this->ids($this->relationship($teams[1] ?? null, 'members')['data'] ?? null));
        self::assertSame(['fk-members:1', 'fk-members:2', 'fk-members:4'], $this->includedKeys($document));
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetch(string $path, ?string $accept = null): array
    {
        $response = $this->handle($path, extraServer: $accept === null ? [] : ['HTTP_ACCEPT' => $accept]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    protected function relationship(mixed $resource, string $name): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship, $name . ' is rendered');

        /** @var array<string, mixed> $relationship */
        return $relationship;
    }

    /**
     * @return list<string>
     */
    protected function ids(mixed $data): array
    {
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $item) {
            self::assertIsArray($item);
            $id = $item['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    protected function includedKeys(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);
        $keys = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            $keys[] = $type . ':' . $id;
        }
        \sort($keys);

        return $keys;
    }

    private function attribute(mixed $resource, string $name): mixed
    {
        self::assertIsArray($resource);
        $attributes = $resource['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, string>
     */
    private function links(array $document): array
    {
        $links = $document['links'] ?? [];
        self::assertIsArray($links);

        $hrefs = [];
        foreach ($links as $name => $link) {
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            self::assertIsString($href);
            $hrefs[(string) $name] = $href;
        }

        return $hrefs;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function meta(array $document): array
    {
        $meta = $document['meta'] ?? [];
        self::assertIsArray($meta);

        return $meta;
    }
}
