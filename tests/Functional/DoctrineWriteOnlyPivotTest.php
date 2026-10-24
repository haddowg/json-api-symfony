<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistTrackEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write-only pivot field (`source`, declared `writeOnly()`, and `curator`, declared
 * `writeOnly(when:)`) is accepted on a write but left out of every `meta.pivot` a
 * response renders: the related and relationship endpoints, a compound include and
 * a default-rendered linkage. The seed stores both values on every row, so a
 * response that rendered them would show them. The documents echoed by writes carry
 * no `meta.pivot` today; the write tests pin that they carry no write-only value
 * either, and read the written row back through the related endpoint.
 */
final class DoctrineWriteOnlyPivotTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrinePivot;

    private const string BASE_URI = 'https://example.test';

    private const array WRITE_ONLY = ['source', 'curator'];

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readPaths(): iterable
    {
        yield 'related endpoint' => ['/playlists/1/tracks'];
        yield 'related endpoint, sorted by pivot' => ['/playlists/1/tracks?sort=position'];
        yield 'relationship endpoint' => ['/playlists/1/relationships/tracks'];
        yield 'include on a single resource' => ['/playlists/1?include=tracks'];
        yield 'include on a collection' => ['/playlists?include=tracks'];
        yield 'default-rendered linkage' => ['/playlists/1'];
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[DataProvider('readPaths')]
    public function aReadLeavesWriteOnlyPivotFieldsOut(string $path): void
    {
        $response = $this->handle(self::BASE_URI . $path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertPivotsOmitWriteOnlyFields($response);
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aRelationshipAddAcceptsWriteOnlyPivotFieldsAndEchoesWithoutThem(): void
    {
        $response = $this->handle(
            self::BASE_URI . '/playlists/2/relationships/tracks',
            'POST',
            ['data' => [['type' => 'tracks', 'id' => '3', 'meta' => ['pivot' => [
                'position' => 5,
                'source' => 'api',
                'curator' => 'editor',
            ]]]]],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNoWriteOnlyPivotField($response);

        $row = $this->storedRow(2, 3);
        self::assertSame('api', $row->source);
        self::assertSame('editor', $row->curator);

        $this->assertPivotsOmitWriteOnlyFields($this->handle(self::BASE_URI . '/playlists/2/tracks'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aRelationshipReplaceEchoesWithoutWriteOnlyPivotFields(): void
    {
        $response = $this->handle(
            self::BASE_URI . '/playlists/1/relationships/tracks',
            'PATCH',
            ['data' => [
                ['type' => 'tracks', 'id' => '3', 'meta' => ['pivot' => ['position' => 1, 'source' => 'api']]],
                ['type' => 'tracks', 'id' => '1', 'meta' => ['pivot' => ['position' => 3]]],
            ]],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNoWriteOnlyPivotField($response);
        $this->assertPivotsOmitWriteOnlyFields($this->handle(self::BASE_URI . '/playlists/1/relationships/tracks'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aWholeResourceUpdateEchoesWithoutWriteOnlyPivotFields(): void
    {
        $response = $this->handle(
            self::BASE_URI . '/playlists/1?include=tracks',
            'PATCH',
            ['data' => [
                'type' => 'playlists',
                'id' => '1',
                'relationships' => [
                    'tracks' => ['data' => [
                        ['type' => 'tracks', 'id' => '1', 'meta' => ['pivot' => ['position' => 1, 'source' => 'api']]],
                    ]],
                ],
            ]],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNoWriteOnlyPivotField($response);
        self::assertSame('api', $this->storedRow(1, 1)->source);
        $this->assertPivotsOmitWriteOnlyFields($this->handle(self::BASE_URI . '/playlists/1?include=tracks'));
    }

    #[Test]
    #[Group('spec:creating-resources')]
    public function aCreateEchoesWithoutWriteOnlyPivotFields(): void
    {
        $response = $this->handle(
            self::BASE_URI . '/playlists?include=tracks',
            'POST',
            ['data' => [
                'type' => 'playlists',
                'attributes' => ['name' => 'Inline'],
                'relationships' => [
                    'tracks' => ['data' => [
                        ['type' => 'tracks', 'id' => '2', 'meta' => ['pivot' => ['position' => 1, 'source' => 'api', 'curator' => 'editor']]],
                    ]],
                ],
            ]],
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNoWriteOnlyPivotField($response);

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);
        self::assertSame('editor', $this->storedRow((int) $id, 2)->curator);
        $this->assertPivotsOmitWriteOnlyFields($this->handle(self::BASE_URI . '/playlists/' . $id . '/tracks'));
    }

    /**
     * Every `meta.pivot` in the document carries its readable fields and none of the
     * write-only ones. At least one pivot must render, so the check can't pass on a
     * document that carries none.
     */
    private function assertPivotsOmitWriteOnlyFields(Response $response): void
    {
        $pivots = $this->pivots($this->decode($response));
        self::assertNotSame([], $pivots, 'expected meta.pivot in ' . $response->getContent());

        foreach ($pivots as $pivot) {
            self::assertArrayHasKey('position', $pivot);
            foreach (self::WRITE_ONLY as $field) {
                self::assertArrayNotHasKey($field, $pivot, (string) $response->getContent());
            }
        }
    }

    private function assertNoWriteOnlyPivotField(Response $response): void
    {
        foreach ($this->pivots($this->decode($response)) as $pivot) {
            foreach (self::WRITE_ONLY as $field) {
                self::assertArrayNotHasKey($field, $pivot, (string) $response->getContent());
            }
        }
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<array<mixed>>
     */
    private function pivots(array $node): array
    {
        $found = [];
        $meta = $node['meta'] ?? null;
        if (\is_array($meta) && \is_array($meta['pivot'] ?? null)) {
            $found[] = $meta['pivot'];
        }
        foreach ($node as $child) {
            if (\is_array($child)) {
                $found = [...$found, ...$this->pivots($child)];
            }
        }

        return $found;
    }

    private function storedRow(int $playlistId, int $trackId): PlaylistTrackEntity
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();

        $row = $entityManager->getRepository(PlaylistTrackEntity::class)
            ->findOneBy(['playlist' => $playlistId, 'track' => $trackId]);
        self::assertInstanceOf(PlaylistTrackEntity::class, $row);

        return $row;
    }
}
