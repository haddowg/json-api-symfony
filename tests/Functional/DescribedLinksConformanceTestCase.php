<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\DescribedLinks\DescribedLinksInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The dual-provider contract for built links as link objects with a `describedby`
 * (bundle ADR 0132): identical assertions against the in-memory
 * ({@see InMemoryDescribedLinksTest}) and Doctrine ({@see DoctrineDescribedLinksTest})
 * kernels, which serve the articles surface and an `asLink` action under `/api`.
 *
 * Each `describedby` is checked twice: against the pointer the suite expects, and by
 * resolving it in the document `/api/docs.json` actually serves, so a link and the
 * document can't drift apart unnoticed.
 */
abstract class DescribedLinksConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string BASE = 'http://localhost/api';

    private const string DOCUMENT = '/api/docs.json';

    #[Test]
    #[Group('spec:document-links')]
    public function aResourceAndItsDocumentLinksPointAtTheFetchOperation(): void
    {
        $document = $this->fetch('/api/articles/1');

        $self = ['href' => self::BASE . '/articles/1', 'describedby' => $this->pointer('/articles/{id}')];
        self::assertSame($self, self::at($this->dataOf($document), 'links', 'self'));
        self::assertSame($self, self::at($document, 'links', 'self'));
        self::assertSame(['href' => $this->pointer('/articles/{id}')], self::at($document, 'links', 'describedby'));

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('spec:document-links')]
    public function relationshipLinksPointAtTheRelationshipAndRelatedOperations(): void
    {
        $document = $this->fetch('/api/articles/1');

        self::assertSame(
            [
                'self' => [
                    'href' => self::BASE . '/articles/1/relationships/author',
                    'describedby' => $this->pointer('/articles/{id}/relationships/author'),
                ],
                'related' => [
                    'href' => self::BASE . '/articles/1/author',
                    'describedby' => $this->pointer('/articles/{id}/author'),
                ],
            ],
            self::at($this->dataOf($document), 'relationships', 'author', 'links'),
        );
    }

    #[Test]
    #[Group('spec:document-links')]
    #[Group('spec:fetching-pagination')]
    public function collectionPaginationLinksPointAtTheCollectionOperation(): void
    {
        $document = $this->fetch('/api/articles?page[number]=2&page[size]=2');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        foreach (['self', 'first', 'prev', 'next', 'describedby'] as $name) {
            self::assertIsArray($links[$name] ?? null, $name);
        }
        self::assertSame(
            ['href' => self::BASE . '/articles?page%5Bnumber%5D=3&page%5Bsize%5D=2', 'describedby' => $this->pointer('/articles')],
            $links['next'],
        );
        self::assertSame($this->pointer('/articles'), self::at($links, 'first', 'describedby'));
        self::assertSame(['href' => $this->pointer('/articles')], $links['describedby']);

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('spec:document-links')]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipEndpointsLinksPointAtTheRelationshipOperation(): void
    {
        $document = $this->fetch('/api/articles/1/relationships/pagedComments?page[size]=1');

        $relationship = $this->pointer('/articles/{id}/relationships/pagedComments');
        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertSame(['href' => self::BASE . '/articles/1/relationships/pagedComments', 'describedby' => $relationship], $links['self'] ?? null);
        self::assertSame(['href' => self::BASE . '/articles/1/pagedComments', 'describedby' => $this->pointer('/articles/{id}/pagedComments')], $links['related'] ?? null);
        self::assertSame(
            ['href' => self::BASE . '/articles/1/relationships/pagedComments?page%5Bnumber%5D=2&page%5Bsize%5D=1', 'describedby' => $relationship],
            $links['next'] ?? null,
        );
        self::assertSame(['href' => $relationship], $links['describedby'] ?? null);

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('spec:document-links')]
    #[Group('spec:fetching-relationships')]
    public function aRelatedEndpointPointsItsDocumentAtTheRelatedOperationAndItsDataAtTheRelatedType(): void
    {
        $document = $this->fetch('/api/articles/1/author');

        self::assertSame(
            ['href' => self::BASE . '/articles/1/author', 'describedby' => $this->pointer('/articles/{id}/author')],
            self::at($document, 'links', 'self'),
        );
        self::assertSame(
            ['href' => self::BASE . '/authors/1', 'describedby' => $this->pointer('/authors/{id}')],
            self::at($this->dataOf($document), 'links', 'self'),
        );

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('spec:actions')]
    public function anActionLinkPointsAtTheActionsFirstDeclaredMethod(): void
    {
        $document = $this->fetch('/api/articles/1');

        self::assertSame(
            [
                'href' => self::BASE . '/articles/1/-actions/publish',
                'describedby' => $this->pointer('/articles/{id}/-actions/publish', 'patch'),
            ],
            self::at($this->dataOf($document), 'links', 'publish'),
        );

        $this->assertEveryDescribedbyResolves($document);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theServedDocumentDescribesTheLinksTheResponsesCarry(): void
    {
        $links = self::at($this->servedDocument(), 'components', 'schemas', 'ArticlesResourceLinks');
        self::assertIsArray($links);

        $self = self::at($links, 'properties', 'self');
        self::assertIsArray($self);
        self::assertSame('#/components/schemas/RenderedLink', $self['$ref'] ?? null);
        self::assertSame($this->pointer('/articles/{id}'), self::at($self, 'example', 'describedby'));

        // The asLink action is listed by name, described by its first method.
        self::assertSame(
            $this->pointer('/articles/{id}/-actions/publish', 'patch'),
            self::at($links, 'properties', 'publish', 'example', 'describedby'),
        );
    }

    #[Test]
    #[Group('spec:document-links')]
    public function linksRenderAsStringsWhenLinkObjectsAreTurnedOff(): void
    {
        $this->bootEnvironment('strings');

        $document = $this->fetch('/api/articles/1');

        self::assertSame(['publish' => self::BASE . '/articles/1/-actions/publish', 'self' => self::BASE . '/articles/1'], $this->dataOf($document)['links'] ?? null);
        self::assertSame(
            ['self' => self::BASE . '/articles/1/relationships/author', 'related' => self::BASE . '/articles/1/author'],
            self::at($this->dataOf($document), 'relationships', 'author', 'links'),
        );
        self::assertSame(self::BASE . '/articles/1', self::at($document, 'links', 'self'));
        // A string can't carry a describedby, but the document's own one still renders.
        self::assertSame($this->pointer('/articles/{id}'), self::at($document, 'links', 'describedby'));

        // And the document describes strings, not link objects.
        $self = self::at($this->servedDocument(), 'components', 'schemas', 'ArticlesResourceLinks', 'properties', 'self');
        self::assertIsArray($self);
        self::assertSame('#/components/schemas/LinkObject', $self['$ref'] ?? null);
    }

    #[Test]
    #[Group('spec:document-links')]
    public function aConfiguredDocumentUrlIsUsedAsGiven(): void
    {
        $this->bootEnvironment('published');

        $document = $this->fetch('/api/articles/1');

        $url = \str_replace('{server}', 'default', DescribedLinksInMemoryTestKernel::PUBLISHED_URL);
        $describedby = $url . '#/paths/~1articles~1%7Bid%7D/get';
        self::assertSame(['href' => self::BASE . '/articles/1', 'describedby' => $describedby], self::at($this->dataOf($document), 'links', 'self'));
        self::assertSame(['href' => $describedby], self::at($document, 'links', 'describedby'));

        // The fragments are the served document's, wherever it's published.
        $this->assertEveryDescribedbyResolves($document, $url);
    }

    #[Test]
    #[Group('spec:document-links')]
    public function linksCarryNoDescribedbyWhenNoDocumentIsServed(): void
    {
        $this->bootEnvironment('unserved');

        self::assertSame(404, $this->handle(self::DOCUMENT)->getStatusCode());

        $document = $this->fetch('/api/articles/1');

        self::assertSame(['publish' => ['href' => self::BASE . '/articles/1/-actions/publish'], 'self' => ['href' => self::BASE . '/articles/1']], $this->dataOf($document)['links'] ?? null);
        self::assertSame(['self' => ['href' => self::BASE . '/articles/1']], $document['links'] ?? null);
    }

    private function bootEnvironment(string $environment): void
    {
        self::ensureKernelShutdown();
        static::bootKernel(['debug' => false, 'environment' => $environment]);
        $this->afterBoot();
    }

    /**
     * The `describedby` the suite expects for an operation of the served document.
     */
    private function pointer(string $path, string $method = 'get'): string
    {
        return self::DOCUMENT . '#/paths/' . \str_replace(['~', '/', '{', '}'], ['~0', '~1', '%7B', '%7D'], $path) . '/' . $method;
    }

    /**
     * Walks the whole document and resolves every `describedby` it carries inside the
     * served document: each must be `$url` plus a fragment that lands on an operation.
     *
     * @param array<array-key, mixed> $document
     */
    private function assertEveryDescribedbyResolves(array $document, string $url = self::DOCUMENT): void
    {
        $served = $this->servedDocument();
        $found = 0;

        \array_walk_recursive($document, static function (mixed $value, int|string $key) use ($served, $url, &$found): void {
            if ($key !== 'describedby' && !(\is_string($value) && \str_contains($value, '#/paths/'))) {
                return;
            }
            self::assertIsString($value);
            [$documentUrl, $fragment] = \explode('#', $value, 2) + [1 => ''];
            self::assertSame($url, $documentUrl, $value);

            $node = $served;
            foreach (\array_slice(\explode('/', \rawurldecode($fragment)), 1) as $segment) {
                $segment = \str_replace(['~1', '~0'], ['/', '~'], $segment);
                self::assertIsArray($node);
                self::assertArrayHasKey($segment, $node, \sprintf('%s does not resolve in the served document', $value));
                $node = $node[$segment];
            }
            self::assertIsArray($node);
            self::assertArrayHasKey('responses', $node, \sprintf('%s does not name an operation', $value));
            ++$found;
        });

        self::assertGreaterThan(0, $found, 'the document carries no describedby');
    }

    /**
     * @return array<string, mixed>
     */
    private function servedDocument(): array
    {
        $response = $this->handle(self::DOCUMENT);
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function dataOf(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * The value at `$path` inside `$value`, or null where the path runs out.
     */
    private static function at(mixed $value, string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
