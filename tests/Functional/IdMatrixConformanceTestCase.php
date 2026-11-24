<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixSeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every read, filter and write path of a relation read through a foreign key, across the
 * id kinds a key can take: an integer, a string, a Symfony uuid and ulid, a guid, a
 * custom Doctrine type holding a value object, and a uuid behind a byte-decoding and a
 * prefix-style id encoder. The posts and videos read their notes back through a MorphTo
 * identified by columns whose integer ids collide, so a read that compared the id alone
 * would leak the other type's notes. Asserted identically on the Doctrine
 * ({@see DoctrineIdMatrixTest}) and in-memory ({@see InMemoryIdMatrixTest}) kernels.
 */
abstract class IdMatrixConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    /**
     * @return iterable<string, array{string}>
     */
    public static function kinds(): iterable
    {
        foreach (['int', 'str', 'uuid', 'ulid', 'guid', 'code', 'enc', 'prefix'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[Test]
    #[DataProvider('kinds')]
    public function theRelatedAndRelationshipEndpointsServeTheOwnersNotes(string $kind): void
    {
        self::assertSame(['1', '2'], $this->ids($this->fetch($this->owner($kind, 0) . '/notes')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->fetch($this->owner($kind, 1) . '/notes')['data'] ?? null));
        self::assertSame([], $this->ids($this->fetch($this->owner($kind, 2) . '/notes')['data'] ?? null));
        self::assertSame(['1', '2'], $this->ids($this->fetch($this->owner($kind, 0) . '/relationships/notes')['data'] ?? null));
        self::assertSame(['2'], $this->ids($this->fetch($this->owner($kind, 0) . '/notes?page[number]=2&page[size]=1')['data'] ?? null));
    }

    #[Test]
    #[DataProvider('kinds')]
    public function linkageIncludesAndWindowedIncludesPartitionByOwner(string $kind): void
    {
        $document = $this->fetch($this->owner($kind, 0) . '?include=notes');
        self::assertSame(['1', '2'], $this->ids($this->relationship($document['data'] ?? null, 'notes')['data'] ?? null));
        self::assertSame(['im-notes:1', 'im-notes:2'], $this->includedKeys($document));

        $owners = $this->rowsById($this->fetch('/im-' . $kind . '-owners?include=notes'));
        self::assertSame(['1', '2'], $this->ids($this->relationship($owners[IdMatrixSeed::wireId($kind, 0)] ?? null, 'notes')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->relationship($owners[IdMatrixSeed::wireId($kind, 1)] ?? null, 'notes')['data'] ?? null));
        self::assertSame([], $this->ids($this->relationship($owners[IdMatrixSeed::wireId($kind, 2)] ?? null, 'notes')['data'] ?? null));
    }

    #[Test]
    #[DataProvider('kinds')]
    public function withCountCountsEachOwnersNotes(string $kind): void
    {
        $owners = $this->rowsById($this->fetch('/im-' . $kind . '-owners?withCount=notes', self::COUNTS_ACCEPT));

        self::assertSame(['total' => 2], $this->relationship($owners[IdMatrixSeed::wireId($kind, 0)] ?? null, 'notes')['meta'] ?? null);
        self::assertSame(['total' => 1], $this->relationship($owners[IdMatrixSeed::wireId($kind, 1)] ?? null, 'notes')['meta'] ?? null);
        self::assertSame(['total' => 0], $this->relationship($owners[IdMatrixSeed::wireId($kind, 2)] ?? null, 'notes')['meta'] ?? null);
    }

    #[Test]
    #[DataProvider('kinds')]
    public function existenceFiltersReachThroughTheForeignKey(string $kind): void
    {
        self::assertSame([IdMatrixSeed::wireId($kind, 0), IdMatrixSeed::wireId($kind, 1)], $this->sortedIds('/im-' . $kind . '-owners?filter[hasNotes]=true'));
        self::assertSame([IdMatrixSeed::wireId($kind, 2)], $this->sortedIds('/im-' . $kind . '-owners?filter[noNotes]=true'));
    }

    #[Test]
    #[DataProvider('kinds')]
    public function aSetFilterOnTheForeignKeyFieldMatchesTheWireForm(string $kind): void
    {
        $key = $kind === 'prefix' || $kind === 'enc'
            // A field filter compares the stored key, which these encoders don't decode.
            ? \substr(IdMatrixSeed::wireId($kind, 0), $kind === 'prefix' ? 4 : 0)
            : IdMatrixSeed::wireId($kind, 0);

        self::assertSame(['1', '2'], $this->ids($this->fetch('/im-notes?filter[' . $kind . 'Owner]=' . \urlencode($key))['data'] ?? null));
        self::assertSame([], $this->ids($this->fetch('/im-notes?filter[' . $kind . 'Owner]=nothing-like-a-key')['data'] ?? null));
    }

    #[Test]
    #[DataProvider('kinds')]
    public function aRelationshipWriteStoresTheOwnersKey(string $kind): void
    {
        $owner = $this->owner($kind, 2);
        $response = $this->handle($owner . '/relationships/notes', 'POST', ['data' => [['type' => 'im-notes', 'id' => '4']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['4'], $this->ids($this->fetch($owner . '/notes')['data'] ?? null));

        $response = $this->handle($owner . '/relationships/notes', 'PATCH', ['data' => [['type' => 'im-notes', 'id' => '3']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['3'], $this->ids($this->fetch($owner . '/notes')['data'] ?? null));
        self::assertSame([], $this->ids($this->fetch($this->owner($kind, 1) . '/notes')['data'] ?? null));
    }

    #[Test]
    public function aPolymorphicInverseReadsOnlyItsOwnTypesRows(): void
    {
        self::assertSame(['1', '3'], $this->ids($this->fetch('/im-posts/1/notes')['data'] ?? null));
        self::assertSame(['2'], $this->ids($this->fetch('/im-videos/1/notes')['data'] ?? null));
        self::assertSame([], $this->ids($this->fetch('/im-posts/2/notes')['data'] ?? null));
        self::assertSame(['1', '3'], $this->ids($this->fetch('/im-posts/1/relationships/notes')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->fetch('/im-posts/1/notes?page[number]=2&page[size]=1')['data'] ?? null));
        self::assertSame(['1'], $this->ids($this->fetch('/im-posts/1/notes?filter[intOwner]=1')['data'] ?? null));
        self::assertSame(['2'], $this->ids($this->fetch('/im-videos/1/notes?filter[intOwner]=1')['data'] ?? null));
    }

    #[Test]
    public function aPolymorphicInverseHasOneReadsItsOwnTypesRow(): void
    {
        $first = $this->fetch('/im-videos/1/firstNote')['data'] ?? null;
        self::assertIsArray($first);
        self::assertSame('2', $first['id'] ?? null);
        self::assertNull($this->fetch('/im-posts/2/firstNote')['data'] ?? null);
        self::assertSame(['type' => 'im-notes', 'id' => '4'], $this->relationship($this->fetch('/im-videos/2')['data'] ?? null, 'firstNote')['data'] ?? null);
    }

    #[Test]
    public function aPolymorphicInverseIncludesAndCountsPerOwner(): void
    {
        $document = $this->fetch('/im-posts?include=notes,firstNote&withCount=notes', self::COUNTS_ACCEPT);
        $posts = $this->rowsById($document);

        self::assertSame(['1', '3'], $this->ids($this->relationship($posts['1'] ?? null, 'notes')['data'] ?? null));
        self::assertSame([], $this->ids($this->relationship($posts['2'] ?? null, 'notes')['data'] ?? null));
        self::assertSame(['total' => 2], $this->relationship($posts['1'] ?? null, 'notes')['meta'] ?? null);
        self::assertSame(['total' => 0], $this->relationship($posts['2'] ?? null, 'notes')['meta'] ?? null);
        self::assertSame(['im-notes:1', 'im-notes:3'], $this->sorted($this->includedKeys($document)));

        $videos = $this->rowsById($this->fetch('/im-videos?include=notes&withCount=notes', self::COUNTS_ACCEPT));
        self::assertSame(['2'], $this->ids($this->relationship($videos['1'] ?? null, 'notes')['data'] ?? null));
        self::assertSame(['total' => 1], $this->relationship($videos['1'] ?? null, 'notes')['meta'] ?? null);
        self::assertSame(['4'], $this->ids($this->relationship($videos['2'] ?? null, 'notes')['data'] ?? null));
    }

    #[Test]
    public function filtersThroughAPolymorphicInverseCompareTheType(): void
    {
        self::assertSame(['1'], $this->sortedIds('/im-posts?filter[hasNotes]=true'));
        self::assertSame(['2'], $this->sortedIds('/im-posts?filter[noNotes]=true'));
        self::assertSame(['1', '2'], $this->sortedIds('/im-videos?filter[hasNotes]=true'));
        self::assertSame([], $this->sortedIds('/im-posts?filter[noteTitle]=Two'));
        self::assertSame(['1'], $this->sortedIds('/im-videos?filter[noteTitle]=Two'));
        self::assertSame(['1'], $this->sortedIds('/im-posts?filter[noteTitle]=Three'));
    }

    #[Test]
    public function aPolymorphicInverseWriteSetsBothFields(): void
    {
        $response = $this->handle('/im-posts/2/relationships/notes', 'POST', ['data' => [['type' => 'im-notes', 'id' => '4']]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['4'], $this->ids($this->fetch('/im-posts/2/notes')['data'] ?? null));
        self::assertSame([], $this->ids($this->fetch('/im-videos/2/notes')['data'] ?? null));
        self::assertSame(['type' => 'im-posts', 'id' => '2'], $this->relationship($this->fetch('/im-notes/4')['data'] ?? null, 'subject')['data'] ?? null);

        $response = $this->handle('/im-videos/1/relationships/firstNote', 'PATCH', ['data' => ['type' => 'im-notes', 'id' => '3']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['type' => 'im-videos', 'id' => '1'], $this->relationship($this->fetch('/im-notes/3')['data'] ?? null, 'subject')['data'] ?? null);
        self::assertSame(['1'], $this->ids($this->fetch('/im-posts/1/notes')['data'] ?? null));
    }

    #[Test]
    public function aMorphToWhoseIdColumnHoldsAUuidRendersItsLinkage(): void
    {
        self::assertSame(['type' => 'im-uuid-owners', 'id' => IdMatrixSeed::wireId('uuid', 0)], $this->relationship($this->fetch('/im-notes/1')['data'] ?? null, 'target')['data'] ?? null);
        self::assertSame(['im-uuid-owners:' . IdMatrixSeed::wireId('uuid', 0)], $this->includedKeys($this->fetch('/im-notes/1?include=target')));
        self::assertSame(['1'], $this->ids($this->fetch('/im-notes?filter[target][type]=im-uuid-owners')['data'] ?? null));
        self::assertSame(['1'], $this->ids($this->fetch('/im-notes?filter[target][type]=im-uuid-owners&filter[target][id]=' . IdMatrixSeed::wireId('uuid', 0))['data'] ?? null));
        self::assertSame([], $this->ids($this->fetch('/im-notes?filter[target][type]=im-uuid-owners&filter[target][id]=' . IdMatrixSeed::wireId('uuid', 1))['data'] ?? null));
    }

    #[Test]
    public function aUuidStoredInCapitalsRendersAndMatchesInLowerCase(): void
    {
        $guid = IdMatrixSeed::wireId('guid', 0);
        $uuid = IdMatrixSeed::wireId('uuid', 1);

        self::assertSame(['type' => 'im-guid-owners', 'id' => $guid], $this->relationship($this->fetch('/im-notes/2')['data'] ?? null, 'ref')['data'] ?? null);
        self::assertSame(['type' => 'im-uuid-owners', 'id' => $uuid], $this->relationship($this->fetch('/im-notes/3')['data'] ?? null, 'ref')['data'] ?? null);
        self::assertSame(['im-guid-owners:' . $guid], $this->includedKeys($this->fetch('/im-notes/2?include=ref')));
        self::assertSame(['im-uuid-owners:' . $uuid], $this->includedKeys($this->fetch('/im-notes/3?include=ref')));
        self::assertSame(['2'], $this->ids($this->fetch('/im-notes?filter[ref][type]=im-guid-owners&filter[ref][id]=' . $guid)['data'] ?? null));
        self::assertSame(['2'], $this->ids($this->fetch('/im-notes?filter[ref][type]=im-guid-owners')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->fetch('/im-notes?filter[ref][type]=im-uuid-owners&filter[ref][id]=' . $uuid)['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->fetch('/im-notes?filter[ref][type]=im-uuid-owners')['data'] ?? null));
    }

    #[Test]
    public function aMorphManyMatchesAStoredIdInEitherForm(): void
    {
        $owner = $this->owner('prefix', 0);

        self::assertSame(['1', '4'], $this->ids($this->fetch($owner . '/refNotes')['data'] ?? null));
        self::assertSame(['1', '4'], $this->ids($this->relationship($this->fetch($owner)['data'] ?? null, 'refNotes')['data'] ?? null));
        $owners = $this->rowsById($this->fetch('/im-prefix-owners?include=refNotes'));
        self::assertSame(['1', '4'], $this->ids($this->relationship($owners[IdMatrixSeed::wireId('prefix', 0)] ?? null, 'refNotes')['data'] ?? null));
        self::assertSame([], $this->ids($this->relationship($owners[IdMatrixSeed::wireId('prefix', 1)] ?? null, 'refNotes')['data'] ?? null));
        self::assertSame(['1', '4'], $this->ids($this->fetch('/im-notes?filter[ref][type]=im-prefix-owners&filter[ref][id]=' . IdMatrixSeed::wireId('prefix', 0))['data'] ?? null));
    }

    #[Test]
    public function aMorphManyMatchesAUuidStoredInCapitals(): void
    {
        $owners = $this->rowsById($this->fetch('/im-uuid-owners?include=refNotes'));

        self::assertSame(['3'], $this->ids($this->relationship($owners[IdMatrixSeed::wireId('uuid', 1)] ?? null, 'refNotes')['data'] ?? null));
        self::assertSame([], $this->ids($this->relationship($owners[IdMatrixSeed::wireId('uuid', 0)] ?? null, 'refNotes')['data'] ?? null));
        self::assertSame(['3'], $this->ids($this->fetch($this->owner('uuid', 1) . '/refNotes')['data'] ?? null));
    }

    #[Test]
    public function linkRowsWhoseIdColumnHoldsAUuidRenderAndIncludeTheirMembers(): void
    {
        [$first, $second, $third] = [IdMatrixSeed::wireId('uuid', 0), IdMatrixSeed::wireId('uuid', 1), IdMatrixSeed::wireId('uuid', 2)];

        self::assertSame([$second, $third], $this->ids($this->relationship($this->fetch($this->owner('uuid', 0))['data'] ?? null, 'links')['data'] ?? null));
        $owners = $this->rowsById($this->fetch('/im-uuid-owners?include=links'));
        self::assertSame([$second, $third], $this->ids($this->relationship($owners[$first] ?? null, 'links')['data'] ?? null));
        self::assertSame([$first], $this->ids($this->relationship($owners[$second] ?? null, 'links')['data'] ?? null));
        self::assertSame([], $this->ids($this->relationship($owners[$third] ?? null, 'links')['data'] ?? null));
        self::assertSame([$second, $third], $this->ids($this->fetch($this->owner('uuid', 0) . '/links')['data'] ?? null));
    }

    #[Test]
    #[DataProvider('kinds')]
    public function anUnknownIdIsNotFound(string $kind): void
    {
        self::assertSame(404, $this->handle('/im-' . $kind . '-owners/not-an-id')->getStatusCode());
    }

    private function owner(string $kind, int $index): string
    {
        return '/im-' . $kind . '-owners/' . \rawurlencode(IdMatrixSeed::wireId($kind, $index));
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetch(string $path, ?string $accept = null): array
    {
        $response = $this->handle($path, extraServer: $accept === null ? [] : ['HTTP_ACCEPT' => $accept]);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function rowsById(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $id = $row['id'] ?? null;
            self::assertIsString($id);
            $rows[$id] = $row;
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function sortedIds(string $path): array
    {
        return $this->sorted($this->ids($this->fetch($path)['data'] ?? null));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        \sort($values);

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function relationship(mixed $resource, string $name): array
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
    private function ids(mixed $data): array
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
    private function includedKeys(array $document): array
    {
        $keys = [];
        foreach (\is_array($document['included'] ?? null) ? $document['included'] : [] as $resource) {
            self::assertIsArray($resource);
            $keys[] = (\is_string($resource['type'] ?? null) ? $resource['type'] : '') . ':' . (\is_string($resource['id'] ?? null) ? $resource['id'] : '');
        }

        return $keys;
    }
}
