<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * An artist created without a `slug` gets one derived from its name, unique among
 * artists, so the singular `filter[slug]` still finds exactly that artist. An empty
 * slug is a `422` on create and update, so no write can store one.
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

    #[Test]
    #[Group('spec:updating-resources')]
    public function anEmptySlugIsRejectedOnCreateAndUpdate(): void
    {
        $create = $this->handle('/artists', 'POST', [
            'data' => ['type' => 'artists', 'attributes' => ['name' => 'Air', 'slug' => '']],
        ]);
        $this->assertEmptySlugRejected($create);

        $update = $this->handle('/artists/1', 'PATCH', [
            'data' => ['type' => 'artists', 'id' => '1', 'attributes' => ['slug' => '']],
        ]);
        $this->assertEmptySlugRejected($update);

        // The stored slug is untouched, so the lookup still finds the artist.
        self::assertSame('1', $this->artistIdBySlug('radiohead'));
    }

    private function assertEmptySlugRejected(Response $response): void
    {
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertCount(1, $errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame(['pointer' => '/data/attributes/slug'], $error['source'] ?? null);
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
