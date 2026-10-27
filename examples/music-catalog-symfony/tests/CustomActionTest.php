<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\AlbumStatus;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The custom / non-CRUD actions acceptance suite (G13, bundle ADR 0076): the example
 * app's three witness actions hanging off the `albums` type under the reserved
 * `-actions` segment, exercised end to end over the reference Doctrine data layer.
 *
 *  - `POST /albums/{id}/-actions/reissue` — a resource-scope `Document` action gated by
 *    `is_granted('ROLE_ADMIN')`: a `403` for a non-admin, then an admin reissues the
 *    album under a new `releasedAt`, persisted and rendered back.
 *  - `POST /albums/{id}/-actions/artwork` — a Raw-input binary upload returning `204`,
 *    its bytes attached to the album (observed through a follow-up fetch).
 *  - `POST /albums/-actions/summary` — a collection-scope action returning a meta-only
 *    catalogue summary.
 *
 * It also asserts route ordering: the action paths do not shadow the normal
 * `/albums/{id}` fetch (and vice versa), and an unknown action under `-actions` is a
 * `404`.
 */
#[Group('spec:actions')]
final class CustomActionTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function aResourceScopeDocumentActionReissuesAnAlbumForAnAdmin(): void
    {
        // The reissue document is validated against the mount `albums` type before the
        // security gate runs (design §5), so it carries the required `title` alongside
        // the new `releasedAt`.
        $body = [
            'data' => [
                'type' => 'albums',
                'attributes' => [
                    'title' => 'OK Computer',
                    'releasedAt' => '2001-10-01T00:00:00+00:00',
                ],
            ],
        ];

        // A non-admin is denied at the per-action security gate.
        $denied = $this->action('/albums/1/-actions/reissue', body: $body, user: 'ada@example.com');
        self::assertSame(403, $denied->getStatusCode(), (string) $denied->getContent());

        // An admin reissues album 1 (OK Computer) under a new release date.
        $response = $this->action('/albums/1/-actions/reissue', body: $body, user: 'admin');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($response);
        self::assertSame('albums', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        // The new release date is persisted: a follow-up read reflects it.
        $fetched = $this->attributesOf($this->action('/albums/1', 'GET', user: 'admin'));
        $releasedAt = $fetched['releasedAt'] ?? null;
        self::assertIsString($releasedAt);
        self::assertStringStartsWith('2001-10-01', $releasedAt);
    }

    #[Test]
    public function aRawInputActionUploadsArtworkAndReturns204(): void
    {
        $response = $this->handleRaw(
            '/albums/2/-actions/artwork',
            'COVER-ART-BINARY',
            'image/png',
            extraServer: ['HTTP_AUTHORIZATION' => 'Bearer admin'],
        );

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('', (string) $response->getContent());

        // The uploaded bytes are attached to album 2.
        $fetched = $this->attributesOf($this->action('/albums/2', 'GET', user: 'admin'));
        self::assertSame('COVER-ART-BINARY', $fetched['artwork'] ?? null);
    }

    #[Test]
    public function aCollectionScopeActionReturnsACatalogueSummary(): void
    {
        $response = $this->action('/albums/-actions/summary', user: 'admin');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $meta = $this->decode($response)['meta'] ?? null;
        self::assertIsArray($meta);
        $albums = $meta['albums'] ?? null;
        self::assertIsArray($albums);
        // The seed is three albums: two published (1, 2), one unpublished (3).
        self::assertSame(2, $albums['published'] ?? null);
        self::assertSame(1, $albums['unpublished'] ?? null);
        self::assertSame(3, $albums['total'] ?? null);
    }

    #[Test]
    public function theArtworkUploadAcceptsAFormAndRefusesOtherTypes(): void
    {
        $form = $this->handleRaw(
            '/albums/2/-actions/artwork',
            "--b\r\nContent-Disposition: form-data; name=\"artwork\"; filename=\"cover.png\"\r\nContent-Type: image/png\r\n\r\nFORM-ART\r\n--b--\r\n",
            'multipart/form-data; boundary=b',
            extraServer: ['HTTP_AUTHORIZATION' => 'Bearer admin'],
        );
        self::assertSame(204, $form->getStatusCode(), (string) $form->getContent());

        $blob = $this->handleRaw('/albums/2/-actions/artwork', 'X', 'application/octet-stream', extraServer: ['HTTP_AUTHORIZATION' => 'Bearer admin']);
        self::assertSame(415, $blob->getStatusCode());
    }

    #[Test]
    public function anActionPathDoesNotShadowANormalFetch(): void
    {
        // Route ordering: the resource-scope action path (4 segments) and a normal
        // /albums/{id} fetch (2 segments) both resolve correctly side by side — the
        // -actions segment is never captured as an {id} or a {relationship}.
        $artwork = $this->handleRaw('/albums/2/-actions/artwork', 'X', 'image/png', extraServer: ['HTTP_AUTHORIZATION' => 'Bearer admin']);
        self::assertSame(204, $artwork->getStatusCode(), (string) $artwork->getContent());

        // The collection-scope action (3 segments) likewise coexists with the resource
        // route — -actions never lands as an {id}. A non-action method on that path
        // (e.g. GET, when `summary` is POST-only) is NOT silently swallowed by the
        // generic related route `GET /albums/{id}/{relationship}` matching {id} =
        // `-actions`; the {id} requirement excludes the reserved segment so the router
        // 405s. That regression is asserted on both providers by the bundle's
        // CustomActionConformanceTestCase (where the logger is silenced); the framework
        // logs a routing 405 to stderr, which trips this example suite's
        // beStrictAboutOutputDuringTests, so it is not re-asserted here.
        self::assertSame(200, $this->action('/albums/-actions/summary', user: 'admin')->getStatusCode());

        $fetch = $this->action('/albums/2', 'GET', user: 'admin');
        self::assertSame(200, $fetch->getStatusCode(), (string) $fetch->getContent());
        self::assertSame('2', $this->dataOf($fetch)['id'] ?? null);
    }

    #[Test]
    public function theImportReadsEachAcceptedMediaType(): void
    {
        foreach ([['text/csv', "title\nKind of Blue\n"], ['image/jpeg', 'JPEG'], ['application/json', '[{"title":"Blue Train"}]']] as [$contentType, $body]) {
            $response = $this->handleRaw('/albums/-actions/import', $body, $contentType, extraServer: ['HTTP_AUTHORIZATION' => 'Bearer admin']);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $meta = $this->decode($response)['meta'] ?? null;
            self::assertIsArray($meta);
            self::assertSame(['mediaType' => $contentType, 'bytes' => \strlen($body)], $meta['import'] ?? null);
        }

        $refused = $this->handleRaw('/albums/-actions/import', 'title=x', 'application/x-www-form-urlencoded', extraServer: ['HTTP_AUTHORIZATION' => 'Bearer admin']);
        self::assertSame(415, $refused->getStatusCode());
    }

    #[Test]
    public function theExportAnswersInTheNegotiatedMediaType(): void
    {
        $csv = $this->action('/albums/-actions/export', 'GET', user: 'admin', accept: 'text/csv');
        self::assertSame(200, $csv->getStatusCode(), (string) $csv->getContent());
        self::assertStringStartsWith('text/csv', (string) $csv->headers->get('Content-Type'));
        self::assertSame('attachment; filename="albums.csv"', $csv->headers->get('Content-Disposition'));
        self::assertStringStartsWith("id,title\n1,", (string) $csv->getContent());

        $json = $this->action('/albums/-actions/export', 'GET', user: 'admin', accept: 'application/json');
        self::assertSame('{"published":2,"unpublished":1}', $json->getContent());

        $pdf = $this->action('/albums/-actions/export', 'GET', user: 'admin', accept: 'application/pdf');
        self::assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        \ob_start();
        $pdf->sendContent();
        self::assertStringStartsWith('%PDF-1.7', (string) \ob_get_clean());

        $document = $this->action('/albums/-actions/export', 'GET', user: 'admin');
        $meta = $this->decode($document)['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame(['published' => 2, 'unpublished' => 1], $meta['albums'] ?? null);
    }

    #[Test]
    public function theDownloadAnswersAJsonApiClientWithTheLink(): void
    {
        $response = $this->action('/albums/1/-actions/download', 'GET', user: 'ada@example.com');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertFalse($response->headers->has('Location'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('Accept', (string) $response->headers->get('Vary'));

        $document = $this->decode($response);
        self::assertSame([
            'href' => 'https://storage.example.com/albums/1.csv?signature=example',
            'type' => 'text/csv',
            'meta' => ['expiresAt' => '2026-01-01T11:05:00+00:00'],
        ], self::at($document, 'links', 'location'));
        self::assertSame(['expiresAt' => '2026-01-01T11:05:00+00:00'], $document['meta'] ?? null);
    }

    #[Test]
    public function theDownloadRedirectsABrowserToTheFile(): void
    {
        $response = $this->action('/albums/1/-actions/download', 'GET', user: 'ada@example.com', accept: 'text/html,application/xhtml+xml,*/*;q=0.8');

        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('https://storage.example.com/albums/1.csv?signature=example', $response->headers->get('Location'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
    }

    #[Test]
    public function anAlbumLinksToItsDownload(): void
    {
        $links = $this->dataOf($this->action('/albums/1', 'GET', user: 'ada@example.com'))['links'] ?? null;
        self::assertIsArray($links);

        $download = $links['download'] ?? null;
        $href = \is_array($download) ? ($download['href'] ?? null) : $download;
        self::assertIsString($href);
        // On the configured base_uri, like the album's own self link.
        self::assertSame('https://music.example/albums/1/-actions/download', $href);
    }

    #[Test]
    public function anAlbumLinksToItsDownloadOnlyWhileReleased(): void
    {
        // The admin server links the download too, while the album is released.
        $released = $this->dataOf($this->action('/admin/albums/1', 'GET'))['links'] ?? null;
        self::assertIsArray($released);
        self::assertArrayHasKey('download', $released);

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $album = $entityManager->find(Album::class, 1);
        self::assertInstanceOf(Album::class, $album);
        $album->status = AlbumStatus::Withdrawn->value;
        $entityManager->flush();
        $entityManager->clear();

        $withdrawn = $this->dataOf($this->action('/albums/1', 'GET'))['links'] ?? null;
        self::assertIsArray($withdrawn);
        self::assertArrayNotHasKey('download', $withdrawn);
        self::assertArrayHasKey('self', $withdrawn);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDownloadLinkCarriesItsDeclaredDescription(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        $description = self::at($document, 'components', 'schemas', 'AlbumsResourceLinks', 'properties', 'download', 'description');
        self::assertIsString($description);
        self::assertStringStartsWith('A short-lived link to the album as CSV, present while the album is released.', $description);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDownloadDocumentsBothResponses(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        $operation = self::at($document, 'paths', '/albums/{id}/-actions/download', 'get');
        self::assertSame(['readOnly' => true], self::at($operation, 'x-safety'));
        self::assertSame('A short-lived link to the album as CSV.', self::at($operation, 'responses', '200', 'description'));
        self::assertSame('A short-lived link to the album as CSV.', self::at($operation, 'responses', '303', 'description'));
        self::assertSame('uri-reference', self::at($operation, 'responses', '303', 'headers', 'Location', 'schema', 'format'));
        self::assertNotNull(self::at($operation, 'responses', '200', 'content', 'application/vnd.api+json', 'schema'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function eachActionDocumentsTheSafetyItDeclares(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        self::assertSame(['readOnly' => true], self::at($document, 'paths', '/albums/-actions/export', 'get', 'x-safety'));
        self::assertSame(['readOnly' => true], self::at($document, 'paths', '/albums/-actions/summary', 'post', 'x-safety'));
        self::assertSame(['idempotent' => true], self::at($document, 'paths', '/albums/{id}/-actions/artwork', 'post', 'x-safety'));
        foreach (['/albums/-actions/import', '/albums/{id}/-actions/reissue'] as $path) {
            self::assertIsArray(self::at($document, 'paths', $path, 'post'), $path);
            self::assertNull(self::at($document, 'paths', $path, 'post', 'x-safety'), $path);
        }
        // The safety tier is 15, the scoped writes raise the document to 16, and the
        // download action's location document to 17.
        self::assertSame(17, self::at($document, 'info', 'x-generator', 'contract'));
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /**
     * Issues an authenticated action request (Bearer token = the user identifier).
     *
     * @param array<string, mixed>|null $body
     */
    private function action(string $path, string $method = 'POST', ?array $body = null, string $user = 'admin', string $accept = 'application/vnd.api+json'): Response
    {
        return $this->handle($path, $method, $body, ['HTTP_AUTHORIZATION' => 'Bearer ' . $user, 'HTTP_ACCEPT' => $accept]);
    }

    /**
     * The decoded document's primary `data` object.
     *
     * @return array<string, mixed>
     */
    private function dataOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The decoded document's `data.attributes`.
     *
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        $attributes = $this->dataOf($response)['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}
