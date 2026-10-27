<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\SeedManifest;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Testing\SchemaConformanceTrait;
use haddowg\JsonApiBundle\Testing\SchemaDocumentKind;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The declared links (core ADR 0178): `albums` declares a bare `store` link, and
 * `tracks` a `preview` link object with a media type and two meta members. Tracks are
 * serialized by {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer\TrackSerializer},
 * which renders the same declaration, and the pivot related endpoint wraps that
 * serializer again, so the link has to survive both.
 */
#[Group('spec:document-links')]
final class DeclaredLinksTest extends MusicCatalogKernelTestCase
{
    use SchemaConformanceTrait;

    private const array PREVIEW_1 = [
        'href' => 'https://cdn.example.com/previews/1.mp3',
        'type' => 'audio/mpeg',
        'meta' => ['format' => 'mp3', 'expiresAt' => '2026-01-01T12:00:00+00:00'],
    ];

    #[Test]
    public function aBareLinkRendersAsAStringOnFetchOne(): void
    {
        self::assertSame('https://store.example.com/albums/1', $this->linksOf($this->data('/albums/1'))['store'] ?? null);
    }

    #[Test]
    public function everyCollectionMemberCarriesItsOwnLink(): void
    {
        foreach ($this->members('/albums') as $album) {
            self::assertSame('https://store.example.com/albums/' . $this->idOf($album), $this->linksOf($album)['store'] ?? null);
        }

        $tracks = $this->members('/tracks');
        self::assertNotSame([], $tracks);
        foreach ($tracks as $track) {
            $preview = $this->linksOf($track)['preview'] ?? null;
            self::assertIsArray($preview);
            self::assertSame('https://cdn.example.com/previews/' . $this->idOf($track) . '.mp3', $preview['href'] ?? null);
        }
    }

    #[Test]
    public function aLinkObjectCarriesItsStaticMembersAndMeta(): void
    {
        self::assertSame(self::PREVIEW_1, $this->linksOf($this->data('/tracks/1'))['preview'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludedResourceCarriesItsLinks(): void
    {
        $included = $this->document('/albums/1?include=tracks')['included'] ?? null;
        self::assertIsArray($included);

        $previews = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            if (($resource['type'] ?? null) === 'tracks') {
                $previews['tracks/' . $this->idOf($resource)] = $this->linksOf($resource)['preview'] ?? null;
            }
        }

        self::assertNotSame([], $previews);
        self::assertSame(self::PREVIEW_1, $previews['tracks/1'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aPivotRelatedMemberKeepsItsLinksBesideItsPivotMeta(): void
    {
        $members = $this->members('/playlists/' . SeedManifest::OWNED_PLAYLIST_ID . '/orderedTracks?sort=position');
        self::assertCount(2, $members);

        foreach ($members as $track) {
            $preview = $this->linksOf($track)['preview'] ?? null;
            self::assertIsArray($preview);
            self::assertSame('https://cdn.example.com/previews/' . $this->idOf($track) . '.mp3', $preview['href'] ?? null);

            $meta = $track['meta'] ?? null;
            self::assertIsArray($meta);
            self::assertArrayHasKey('pivot', $meta);
        }
    }

    #[Test]
    public function aNullHrefLeavesTheLinkOut(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $track = $entityManager->find(Track::class, 4);
        self::assertInstanceOf(Track::class, $track);
        $track->length_seconds = 0;
        $entityManager->flush();
        $entityManager->clear();

        $links = $this->linksOf($this->data('/tracks/4'));
        self::assertArrayNotHasKey('preview', $links);
        self::assertArrayHasKey('self', $links);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theResourceLinksSchemasNameEachDeclaredLink(): void
    {
        $schemas = $this->nested($this->openApi(), 'components', 'schemas');

        $store = $this->nested($schemas, 'AlbumsResourceLinks', 'properties', 'store');
        self::assertSame([['$ref' => '#/components/schemas/LinkObject']], $store['allOf'] ?? null);
        self::assertSame('The album\'s page in the store.', $store['description'] ?? null);
        self::assertSame('https://store.example.com/albums/1', $store['example'] ?? null);

        $preview = $this->nested($schemas, 'TracksResourceLinks', 'properties', 'preview');
        self::assertSame('A short audio preview of the track, valid until expiresAt.', $preview['description'] ?? null);
        self::assertSame(['href', 'type', 'meta'], $preview['required'] ?? null);
        self::assertSame('audio/mpeg', $this->nested($preview, 'properties', 'type')['const'] ?? null);

        $meta = $this->nested($preview, 'properties', 'meta');
        self::assertSame(['format', 'expiresAt'], $meta['required'] ?? null);
        self::assertSame('Audio encoding of the preview.', $this->nested($meta, 'properties', 'format')['description'] ?? null);
        $expiresAt = $this->nested($meta, 'properties', 'expiresAt');
        self::assertSame('date-time', $expiresAt['format'] ?? null);
        self::assertSame('When the preview URL stops working.', $expiresAt['description'] ?? null);

        // Neither link is required: a null href leaves it out.
        $required = $this->nested($schemas, 'TracksResourceLinks')['required'] ?? [];
        self::assertIsArray($required);
        self::assertNotContains('preview', $required);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOperationExamplesCarryTheDeclaredLinks(): void
    {
        $paths = $this->nested($this->openApi(), 'paths');
        $mediaType = 'application/vnd.api+json';

        $album = $this->nested($paths, '/albums/{id}', 'get', 'responses', '200', 'content', $mediaType, 'example', 'data', 'links');
        self::assertSame('https://store.example.com/albums/1', $album['store'] ?? null);

        $track = $this->nested($paths, '/tracks/{id}', 'get', 'responses', '200', 'content', $mediaType, 'example', 'data', 'links');
        self::assertSame([
            'href' => 'https://cdn.example.com/previews/1.mp3',
            'type' => 'audio/mpeg',
            'meta' => ['format' => 'mp3', 'expiresAt' => '2026-01-01T12:00:00+00:00'],
        ], $track['preview'] ?? null);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDocumentIsStampedWithTheDeclaredLinksContract(): void
    {
        // The album actions' declared safety raises the declared-links tier of 14 to 15,
        // and the scoped writes raise it to 16.
        self::assertSame(16, $this->nested($this->openApi(), 'info', 'x-generator')['contract'] ?? null);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theRenderedLinksMatchTheirGeneratedSchemas(): void
    {
        $this->assertResponseMatchesGeneratedSchema($this->handle('/tracks/1'), 'tracks', SchemaDocumentKind::Single);
        $this->assertResponseMatchesGeneratedSchema($this->handle('/tracks'), 'tracks', SchemaDocumentKind::Collection);
        $this->assertResponseMatchesGeneratedSchema($this->handle('/albums/1'), 'albums', SchemaDocumentKind::Single);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function openApi(): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);

        $document = \json_decode((string) \json_encode($factory->forServer()->toArray()), true);
        self::assertIsArray($document);

        return $document;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function data(string $path): array
    {
        $data = $this->document($path)['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function members(string $path): array
    {
        $members = [];
        foreach ($this->data($path) as $member) {
            self::assertIsArray($member);
            $members[] = $member;
        }

        return $members;
    }

    /**
     * @param array<array-key, mixed> $resource
     *
     * @return array<array-key, mixed>
     */
    private function linksOf(array $resource): array
    {
        $links = $resource['links'] ?? null;
        self::assertIsArray($links);

        return $links;
    }

    /**
     * @param array<array-key, mixed> $resource
     */
    private function idOf(array $resource): string
    {
        $id = $resource['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function nested(array $data, string ...$keys): array
    {
        $current = $data;
        foreach ($keys as $key) {
            self::assertIsArray($current);
            self::assertArrayHasKey($key, $current);
            $current = $current[$key];
        }

        self::assertIsArray($current);

        return $current;
    }
}
