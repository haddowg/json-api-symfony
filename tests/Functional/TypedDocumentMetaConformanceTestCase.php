<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Test;

/**
 * A type's documents carry the top-level meta it declares per operation: each member an
 * extractUsing() hook supplies is filled from the document's primary data, a value an
 * After hook sets wins, and a related endpoint serving the type carries its meta too. The
 * generated document narrows each declaring operation's document, and in debug mode a
 * response whose meta does not match fails loudly.
 */
abstract class TypedDocumentMetaConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    public function aCollectionCarriesItsDeclaredMeta(): void
    {
        $meta = $this->metaOf($this->handle('/tm-cards'), 200);

        self::assertSame(2, $meta['open'] ?? null);
        self::assertArrayNotHasKey('note', $meta, 'a member no hook supplies is left to the handler');
    }

    #[Test]
    public function aValueAnAfterHookSetsWins(): void
    {
        $meta = $this->metaOf($this->handle('/tm-cards', extraServer: ['HTTP_X_NOTE' => 'Sprint 4']), 200);

        self::assertSame('Sprint 4', $meta['note'] ?? null);
        self::assertSame(99, $meta['open'] ?? null);
    }

    #[Test]
    public function eachSingleDocumentCarriesItsOwn(): void
    {
        self::assertSame(['etag' => 'card-2-1'], $this->metaOf($this->handle('/tm-cards/2'), 200));
        self::assertSame(['etag' => 'card-1-0'], $this->metaOf($this->handle('/tm-cards/1', 'PATCH', ['data' => ['type' => 'tm-cards', 'id' => '1', 'attributes' => ['title' => 'Plan again']]]), 200));
        self::assertSame(['fresh' => true], $this->metaOf($this->handle('/tm-cards', 'POST', ['data' => ['type' => 'tm-cards', 'attributes' => ['title' => 'Review', 'done' => false]]]), 201));
    }

    #[Test]
    public function aRelatedEndpointCarriesTheRelatedTypesMeta(): void
    {
        self::assertSame(2, $this->metaOf($this->handle('/tm-boards/1/cards'), 200)['open'] ?? null);
        self::assertSame(['etag' => 'card-3-0'], $this->metaOf($this->handle('/tm-boards/1/pinned'), 200));

        $linkage = $this->decode($this->handle('/tm-boards/1/relationships/cards'));
        self::assertArrayNotHasKey('open', \is_array($linkage['meta'] ?? null) ? $linkage['meta'] : [], 'a relationship endpoint serves linkage only');
    }

    #[Test]
    public function aTypeDeclaringNothingCarriesNone(): void
    {
        $document = $this->decode($this->handle('/tm-boards'));

        self::assertSame([], \array_diff(\array_keys(\is_array($document['meta'] ?? null) ? $document['meta'] : []), ['page', 'total']));
    }

    #[Test]
    public function aComposedTypeDeclaresThroughItsSerializer(): void
    {
        self::assertSame(['labels' => 2], $this->metaOf($this->handle('/tm-labels'), 200));
    }

    #[Test]
    public function anAtomicResultCarriesTheDeclaredMeta(): void
    {
        $atomic = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $response = $this->handleRaw('/operations', \json_encode(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'tm-cards', 'attributes' => ['title' => 'Batch', 'done' => false]]],
        ]], \JSON_THROW_ON_ERROR), $atomic, extraServer: ['HTTP_ACCEPT' => $atomic]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $results = $this->decode($response)['atomic:results'] ?? null;
        self::assertIsArray($results);
        self::assertIsArray($results[0] ?? null);
        self::assertSame(['fresh' => true], $results[0]['meta'] ?? null);
    }

    #[Test]
    public function theGeneratedDocumentNarrowsEachDeclaringOperation(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = \json_decode((string) \json_encode($factory->forServer()->toArray()), true);
        self::assertIsArray($document);

        self::assertSame(['contract' => GeneratorContract::TYPED_DOCUMENT_META], self::at($document, 'info', 'x-generator'));

        foreach ([
            ['/tm-cards', 'get', '200', 'TmCardsFetchCollectionDocument'],
            ['/tm-cards/{id}', 'get', '200', 'TmCardsFetchOneDocument'],
            ['/tm-cards/{id}', 'patch', '200', 'TmCardsUpdateDocument'],
            ['/tm-cards', 'post', '201', 'TmCardsCreateDocument'],
            ['/tm-boards/{id}/cards', 'get', '200', 'TmBoardsCardsFetchRelatedDocument'],
            ['/tm-boards/{id}/pinned', 'get', '200', 'TmBoardsPinnedFetchRelatedDocument'],
            ['/tm-labels', 'get', '200', 'TmLabelsFetchCollectionDocument'],
            ['/tm-boards', 'get', '200', 'TmBoardsCollection'],
            ['/tm-boards/{id}', 'get', '200', 'TmBoardsDocument'],
        ] as [$path, $method, $status, $component]) {
            self::assertSame(
                ['$ref' => '#/components/schemas/' . $component],
                self::at($document, 'paths', $path, $method, 'responses', $status, 'content', 'application/vnd.api+json', 'schema'),
                $method . ' ' . $path,
            );
        }

        self::assertTrue(self::at($document, 'components', 'schemas', 'TmCardsFetchCollectionDocument', 'x-document-meta'));
        self::assertSame(['$ref' => '#/components/schemas/TmCardsCollection'], self::at($document, 'components', 'schemas', 'TmCardsFetchCollectionDocument', 'allOf', 0));
        $meta = self::at($document, 'components', 'schemas', 'TmCardsFetchCollectionDocument', 'allOf', 1, 'properties', 'meta');
        self::assertIsArray($meta);
        self::assertSame(['open'], $meta['required'] ?? null);
        self::assertFalse($meta['additionalProperties'] ?? null);
        $properties = $meta['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame(['open', 'note', 'page', 'total'], \array_keys($properties));
        self::assertNull(self::at($document, 'components', 'schemas', 'TmBoardsFetchCollectionDocument'));
    }

    #[Test]
    public function productionChecksNothing(): void
    {
        self::assertSame('two', $this->metaOf($this->handle('/tm-cards', extraServer: ['HTTP_X_WRONG_TYPE' => '1']), 200)['open'] ?? null);
        self::assertTrue($this->metaOf($this->handle('/tm-cards', extraServer: ['HTTP_X_BOGUS_META' => '1']), 200)['bogus'] ?? null);
    }

    #[Test]
    public function debugRefusesAMetaThatDoesNotMatch(): void
    {
        $this->bootInDebug();

        self::assertSame(['etag' => 'card-1-0'], $this->metaOf($this->handle('/tm-cards/1'), 200));
        self::assertSame(2, $this->metaOf($this->handle('/tm-cards'), 200)['open'] ?? null);

        $wrongType = $this->handle('/tm-cards', extraServer: ['HTTP_X_WRONG_TYPE' => '1']);
        self::assertSame(500, $wrongType->getStatusCode());
        self::assertStringContainsString('The top-level meta of the \"tm-cards\" FetchCollection document does not match its declaration: the member \"open\" is string, not integer.', (string) $wrongType->getContent());

        $undeclared = $this->handle('/tm-cards', extraServer: ['HTTP_X_BOGUS_META' => '1']);
        self::assertSame(500, $undeclared->getStatusCode());
        self::assertStringContainsString('the member \"bogus\" is not declared', (string) $undeclared->getContent());
    }

    abstract protected function bootInDebug(): void;

    /**
     * The value at a path into a decoded document, or `null` where it stops.
     *
     * @param array<array-key, mixed> $document
     */
    private static function at(array $document, int|string ...$keys): mixed
    {
        $node = $document;
        foreach ($keys as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function metaOf(\Symfony\Component\HttpFoundation\Response $response, int $status): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        $meta = $this->decode($response)['meta'] ?? [];
        self::assertIsArray($meta);
        unset($meta['page'], $meta['total']);

        return $meta;
    }
}
