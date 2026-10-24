<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * An artist created without a `slug` gets one derived from its name, unique among
 * artists, so the singular `filter[slug]` still finds exactly that artist.
 */
#[Group('spec:creating-resources')]
final class ArtistSlugTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function artistsCreatedWithoutASlugAreEachFoundByTheirDerivedSlug(): void
    {
        $massive = $this->createArtist(['name' => 'Massive Attack']);
        $tricky = $this->createArtist(['name' => 'Tricky']);

        self::assertSame('massive-attack', $massive['slug']);
        self::assertSame('tricky', $tricky['slug']);
        self::assertSame($massive['id'], $this->artistIdBySlug('massive-attack'));
        self::assertSame($tricky['id'], $this->artistIdBySlug('tricky'));
    }

    #[Test]
    public function aDerivedSlugAlreadyTakenGetsANumericSuffix(): void
    {
        // The seed already holds `radiohead`.
        $first = $this->createArtist(['name' => 'Radiohead']);
        $second = $this->createArtist(['name' => 'Radiohead']);

        self::assertSame('radiohead-2', $first['slug']);
        self::assertSame('radiohead-3', $second['slug']);
        self::assertSame('1', $this->artistIdBySlug('radiohead'));
        self::assertSame($first['id'], $this->artistIdBySlug('radiohead-2'));
        self::assertSame($second['id'], $this->artistIdBySlug('radiohead-3'));
    }

    #[Test]
    public function artistsCreatedInOneAtomicBatchGetDistinctSlugs(): void
    {
        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $content = \json_encode(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'artists', 'attributes' => ['name' => 'Goldfrapp']]],
            ['op' => 'add', 'data' => ['type' => 'artists', 'attributes' => ['name' => 'Goldfrapp']]],
        ]], \JSON_THROW_ON_ERROR);

        $response = $this->handleRaw('/operations', $content, $ext, 'POST', ['HTTP_ACCEPT' => $ext]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $results = $this->decode($response)['atomic:results'] ?? null;
        self::assertIsArray($results);
        $slugs = [];
        foreach ($results as $result) {
            self::assertIsArray($result);
            $data = $result['data'] ?? null;
            self::assertIsArray($data);
            $attributes = $data['attributes'] ?? null;
            self::assertIsArray($attributes);
            $slugs[] = $attributes['slug'] ?? null;
        }
        self::assertSame(['goldfrapp', 'goldfrapp-2'], $slugs);
    }

    #[Test]
    public function aSuppliedSlugIsKept(): void
    {
        $artist = $this->createArtist(['name' => 'Portishead Tribute', 'slug' => 'dummy']);

        self::assertSame('dummy', $artist['slug']);
        self::assertSame($artist['id'], $this->artistIdBySlug('dummy'));
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{id: string, slug: mixed}
     */
    private function createArtist(array $attributes): array
    {
        $response = $this->handle('/artists', 'POST', [
            'data' => ['type' => 'artists', 'attributes' => $attributes],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;
        self::assertIsString($id);
        $created = $data['attributes'] ?? null;
        self::assertIsArray($created);

        return ['id' => $id, 'slug' => $created['slug'] ?? null];
    }

    private function artistIdBySlug(string $slug): ?string
    {
        $response = $this->handle('/artists?filter[slug]=' . $slug);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $id = $data['id'] ?? null;

        return \is_string($id) ? $id : null;
    }
}
