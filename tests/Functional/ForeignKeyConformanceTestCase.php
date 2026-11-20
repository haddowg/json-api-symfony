<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

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
    public function anActionTakesARelatedOnlyInputWhoseToManyNoRequestReads(): void
    {
        $response = $this->handle('/fk-users/ada/-actions/import', 'POST', [
            'data' => ['type' => 'fk-imports', 'relationships' => ['secrets' => ['data' => [['type' => 'fk-secrets', 'id' => '1']]]]],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('Ada', $this->attribute($this->decode($response)['data'] ?? null, 'name'));
    }

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

    #[Test]
    public function aToManyWithNoForeignKeyIsReadOffTheModelAndCutInMemory(): void
    {
        self::assertSame(['5', '4'], $this->ids($this->fetch('/fk-users/ada/pinned')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->fetch('/fk-users/ada/pinned?page[number]=2&page[size]=2')['data'] ?? null));
        self::assertSame(['5', '4'], $this->ids($this->fetch('/fk-users/ada/relationships/pinned')['data'] ?? null));

        $document = $this->fetch('/fk-users?include=pinned');
        $users = $document['data'] ?? null;
        self::assertIsArray($users);
        self::assertSame(['5', '4'], $this->ids($this->relationship($users[0] ?? null, 'pinned')['data'] ?? null));
        self::assertSame([], $this->ids($this->relationship($users[1] ?? null, 'pinned')['data'] ?? null));
        self::assertSame(['fk-items:4', 'fk-items:5'], $this->includedKeys($document));
    }

    #[Test]
    public function aHasOneWithNoForeignKeyReadsTheModelsTarget(): void
    {
        $favourite = $this->fetch('/fk-users/ada/favourite');
        self::assertSame('2', \is_array($favourite['data'] ?? null) ? ($favourite['data']['id'] ?? null) : null);

        $included = $this->fetch('/fk-users/ada?include=favourite');
        self::assertSame(['type' => 'fk-items', 'id' => '2'], $this->relationship($included['data'] ?? null, 'favourite')['data'] ?? null);
        self::assertSame(['fk-items:2'], $this->includedKeys($included));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aReplaceSetsTheForeignKeyOnTheLinkedRowsAndClearsItOnTheRest(): void
    {
        $response = $this->handle('/fk-users/ada/relationships/items', 'PATCH', ['data' => [['type' => 'fk-items', 'id' => '1'], ['type' => 'fk-items', 'id' => '7']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '7'], $this->ids($this->decode($response)['data'] ?? null));

        self::assertSame(['1', '7'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
        self::assertNull($this->attribute($this->fetch('/fk-items/2')['data'] ?? null, 'owner'));
        self::assertSame('ada', $this->attribute($this->fetch('/fk-items/7')['data'] ?? null, 'owner'));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function anAddMovesARowFromAnotherParentAndARemoveClearsOnlyMembers(): void
    {
        self::assertSame(200, $this->handle('/fk-users/ada/relationships/items', 'POST', ['data' => [['type' => 'fk-items', 'id' => '6']]])->getStatusCode());
        self::assertSame(['1', '2', '3', '4', '5', '6'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
        self::assertSame([], $this->ids($this->fetch('/fk-users/bob/items')['data'] ?? null));

        $removed = $this->handle('/fk-users/ada/relationships/items', 'DELETE', ['data' => [['type' => 'fk-items', 'id' => '1'], ['type' => 'fk-items', 'id' => '7']]]);
        self::assertSame(200, $removed->getStatusCode(), (string) $removed->getContent());
        self::assertSame(['2', '3', '4', '5', '6'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
        self::assertNull($this->attribute($this->fetch('/fk-items/7')['data'] ?? null, 'owner'));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aHasOneReplaceMovesTheForeignKeyToTheNewTarget(): void
    {
        self::assertSame(200, $this->handle('/fk-users/bob/relationships/profile', 'PATCH', ['data' => ['type' => 'fk-profiles', 'id' => '2']])->getStatusCode());
        self::assertSame(['type' => 'fk-profiles', 'id' => '2'], $this->fetch('/fk-users/bob/relationships/profile')['data'] ?? null);

        self::assertSame(200, $this->handle('/fk-users/ada/relationships/profile', 'PATCH', ['data' => null])->getStatusCode());
        $none = $this->fetch('/fk-users/ada/relationships/profile');
        self::assertArrayHasKey('data', $none);
        self::assertNull($none['data']);
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function anEntityParentWithNoInverseMappingWritesThroughTheForeignKey(): void
    {
        $response = $this->handle('/fk-teams/2/relationships/members', 'PATCH', ['data' => [['type' => 'fk-members', 'id' => '4'], ['type' => 'fk-members', 'id' => '3']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['3', '4'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));
        self::assertSame(['1', '2'], $this->ids($this->fetch('/fk-teams/1/relationships/members')['data'] ?? null));

        $update = $this->handle('/fk-teams/2', 'PATCH', ['data' => ['type' => 'fk-teams', 'id' => '2', 'relationships' => ['members' => ['data' => [['type' => 'fk-members', 'id' => '1']]]]]]);
        self::assertSame(200, $update->getStatusCode(), (string) $update->getContent());
        self::assertSame(['1'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));
        self::assertSame(['2'], $this->ids($this->fetch('/fk-teams/1/relationships/members')['data'] ?? null));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function linkageTheCallerCantReachIsNotFoundAndChangesNothing(): void
    {
        self::assertSame(404, $this->handle('/fk-users/ada/relationships/items', 'PATCH', ['data' => [['type' => 'fk-items', 'id' => '1'], ['type' => 'fk-items', 'id' => '99']]])->getStatusCode());
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function readOnlyAndFixedMembershipStillRefuseTheWrite(): void
    {
        self::assertSame(403, $this->handle('/fk-users/ada/relationships/readOnlyItems', 'PATCH', ['data' => []])->getStatusCode());

        $same = [];
        foreach (['1', '2', '3', '4', '5'] as $id) {
            $same[] = ['type' => 'fk-items', 'id' => $id];
        }
        self::assertSame(200, $this->handle('/fk-users/ada/relationships/lockedItems', 'PATCH', ['data' => $same])->getStatusCode());
        self::assertSame(403, $this->handle('/fk-users/ada/relationships/lockedItems', 'PATCH', ['data' => [['type' => 'fk-items', 'id' => '1']]])->getStatusCode());
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
    }

    #[Test]
    #[Group('spec:actions')]
    public function anActionComparesALockedToManyWithTheItemsTheForeignKeyReads(): void
    {
        // As a PATCH of ada does, the action reads the items holding ada's key.
        $same = [];
        foreach (['5', '4', '3', '2', '1'] as $id) {
            $same[] = ['type' => 'fk-items', 'id' => $id];
        }
        $revise = fn(array $items): Response => $this->handle('/fk-users/ada/-actions/revise', 'PATCH', ['data' => ['type' => 'fk-users', 'id' => 'ada', 'relationships' => ['lockedItems' => ['data' => $items]]]]);

        $kept = $revise($same);
        self::assertSame(200, $kept->getStatusCode(), (string) $kept->getContent());
        // FkUser declares lockedItems, so its input holds the items themselves.
        self::assertSame(['revised' => true, 'lockedItems' => ['5', '4', '3', '2', '1']], $this->decode($kept)['meta'] ?? null);

        $dropped = $revise([['type' => 'fk-items', 'id' => '1']]);
        self::assertSame(403, $dropped->getStatusCode(), (string) $dropped->getContent());
        $errors = $this->decode($dropped)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0] ?? null);
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $errors[0]['code'] ?? null);

        $added = $revise([...$same, ['type' => 'fk-items', 'id' => '7']]);
        self::assertSame(403, $added->getStatusCode(), (string) $added->getContent());
        self::assertSame(['1', '2', '3', '4', '5'], $this->ids($this->fetch('/fk-users/ada/items?page[size]=10')['data'] ?? null));
    }

    #[Test]
    #[Group('spec:actions')]
    public function anActionInputWithNoPropertyForAForeignKeyToManyLeavesItsLinkageToTheHandler(): void
    {
        // FkTeam declares no members property, so its input gains none.
        $regroup = fn(array $members): Response => $this->handle('/fk-teams/1/-actions/regroup', 'PATCH', ['data' => ['type' => 'fk-teams', 'id' => '1', 'relationships' => ['members' => ['data' => $members]]]]);
        $response = $regroup([['type' => 'fk-members', 'id' => '4'], ['type' => 'fk-members', 'id' => '1']]);
        $missing = $regroup([['type' => 'fk-members', 'id' => '99']]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['members' => ['4', '1'], 'inputHoldsMembers' => false], $this->decode($response)['meta'] ?? null);
        self::assertSame(404, $missing->getStatusCode(), (string) $missing->getContent());
        self::assertSame(['1', '2', '3'], $this->ids($this->fetch('/fk-teams/1/members?page[size]=10')['data'] ?? null));
    }

    #[Test]
    #[Group('ext:atomic')]
    public function anAtomicBatchWritesTheForeignKeyAndRollsItBackOnFailure(): void
    {
        $failed = $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'fk-teams', 'id' => '2', 'relationship' => 'members'], 'data' => [['type' => 'fk-members', 'id' => '1']]],
            ['op' => 'update', 'ref' => ['type' => 'fk-teams', 'id' => '1', 'relationship' => 'members'], 'data' => [['type' => 'fk-members', 'id' => '99']]],
        ]);
        self::assertSame(404, $failed->getStatusCode(), (string) $failed->getContent());
        self::assertSame(['4'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));

        $committed = $this->atomic([
            ['op' => 'update', 'ref' => ['type' => 'fk-teams', 'id' => '2', 'relationship' => 'members'], 'data' => [['type' => 'fk-members', 'id' => '1']]],
        ]);
        self::assertContains($committed->getStatusCode(), [200, 204], (string) $committed->getContent());
        self::assertSame(['1'], $this->ids($this->fetch('/fk-teams/2/relationships/members')['data'] ?? null));
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(array $operations): \Symfony\Component\HttpFoundation\Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = \Symfony\Component\HttpFoundation\Request::create('/operations', 'POST', server: ['CONTENT_TYPE' => $ext, 'HTTP_ACCEPT' => $ext], content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
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
