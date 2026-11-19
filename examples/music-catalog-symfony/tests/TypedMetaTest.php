<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Albums declare the `meta` a create may send and their resource objects' `meta`: a create
 * says where the album came from, required inside an atomic batch, each album renders its
 * release year, and a write may leave a note the server reads but never stores.
 */
final class TypedMetaTest extends MusicCatalogKernelTestCase
{
    private const string ATOMIC_EXT = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    #[Test]
    public function anAlbumRendersItsDeclaredMeta(): void
    {
        $data = $this->decode($this->handle('/albums/1'))['data'] ?? null;

        self::assertIsArray($data);
        self::assertSame(['releaseYear' => 1997], $data['meta'] ?? null);
    }

    #[Test]
    public function aCreateTakesItsDeclaredRequestAndResourceMeta(): void
    {
        $response = $this->handle('/albums', 'POST', [
            'data' => ['type' => 'albums', 'attributes' => ['title' => 'Sourced'], 'meta' => ['editorNote' => 'Remastered edition']],
            'meta' => ['source' => 'import'],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        // The note is write-only, so the created album renders without it.
        self::assertArrayNotHasKey('editorNote', \is_array($data['meta'] ?? null) ? $data['meta'] : []);
    }

    #[Test]
    public function aCreateRefusesMetaTheAlbumDoesNotDeclare(): void
    {
        $response = $this->handle('/albums', 'POST', [
            'data' => ['type' => 'albums', 'attributes' => ['title' => 'Unsourced'], 'meta' => ['releaseYear' => 2001, 'mood' => 'calm']],
            'meta' => ['source' => 'fax', 'campaign' => 'spring'],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $pointers = $this->pointers($response);
        \sort($pointers);
        // A read-only member is ignored, as a read-only attribute is.
        self::assertSame(['/data/meta/mood', '/meta/campaign', '/meta/source'], $pointers);
    }

    #[Test]
    public function anAtomicCreateMustSayWhereTheAlbumCameFrom(): void
    {
        $response = $this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'albums', 'attributes' => ['title' => 'Batched']]],
        ], 'meta' => ['anything' => 'goes']]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['/atomic:operations/0/meta/source'], $this->pointers($response));

        $response = $this->atomic(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'albums', 'attributes' => ['title' => 'Batched']], 'meta' => ['source' => 'import']],
        ], 'meta' => ['anything' => 'goes']]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function theDocumentTypesTheDeclaredMeta(): void
    {
        $document = $this->decode($this->handle('/docs.json'));
        $schemas = $document['components']['schemas'] ?? null;
        self::assertIsArray($schemas);

        self::assertSame(['$ref' => '#/components/schemas/AlbumsResourceMeta'], $schemas['AlbumsResource']['properties']['meta'] ?? null);
        self::assertTrue($schemas['AlbumsResourceMeta']['x-resource-meta'] ?? null);
        self::assertTrue($schemas['AlbumsCreateRequestDocument']['x-request-meta'] ?? null);
        self::assertTrue($schemas['AlbumsAtomicAddOperation']['x-request-meta'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function pointers(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? [];
        self::assertIsArray($errors);
        $pointers = [];
        foreach ($errors as $error) {
            self::assertIsArray($error);
            $source = $error['source'] ?? [];
            self::assertIsArray($source);
            $pointer = $source['pointer'] ?? null;
            self::assertIsString($pointer);
            $pointers[] = $pointer;
        }

        return $pointers;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function atomic(array $document): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $request = Request::create('/operations', 'POST', server: [
            'CONTENT_TYPE' => self::ATOMIC_EXT,
            'HTTP_ACCEPT' => self::ATOMIC_EXT,
        ], content: \json_encode($document, \JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }
}
