<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Scope\ScopeTable;
use haddowg\JsonApiBundle\Scope\ScopeTableProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Scopes\HeaderGrantedScopes;
use haddowg\JsonApiBundle\Tests\Functional\App\Scopes\RecordingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * OAuth scope enforcement (core ADRs 0181 to 0183), run against the in-memory provider
 * ({@see InMemoryScopesTest}) and the Doctrine provider ({@see DoctrineScopesTest}).
 *
 * The grant comes from the `X-Granted-Scopes` header; a request without it is not
 * checked. Reads need `<type>:read`, article writes `articles:write`, an article delete
 * `articles:admin` too, the publish action `articles:publish`, and the Atomic Operations
 * endpoint `batch`. Articles include their author by default.
 */
#[Group('scopes')]
abstract class ScopesConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string ATOMIC = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    #[Test]
    public function aRequestWithoutAGrantIsNotChecked(): void
    {
        self::assertSame(Response::HTTP_OK, $this->handle('/articles/1')->getStatusCode());
    }

    #[Test]
    public function aGrantCoveringTheOperationIsServed(): void
    {
        self::assertSame(Response::HTTP_OK, $this->granted('/articles/1', 'articles:read')->getStatusCode());
    }

    #[Test]
    public function aGrantMissingAScopeIsA403WithTheChallenge(): void
    {
        $response = $this->granted('/articles/1', 'authors:read');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('Bearer error="insufficient_scope", scope="articles:read"', $response->headers->get('WWW-Authenticate'));
        $error = $this->error($response);
        self::assertSame('INSUFFICIENT_SCOPE', $error['code'] ?? null);
        self::assertSame(['requiredScopes' => ['articles:read'], 'missingScopes' => ['articles:read']], $error['meta'] ?? null);
    }

    #[Test]
    public function anOverrideOnTheResourceReplacesTheResolver(): void
    {
        $response = $this->granted('/articles/1', 'articles:write', 'DELETE');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(['requiredScopes' => ['articles:write', 'articles:admin'], 'missingScopes' => ['articles:admin']], $this->error($response)['meta'] ?? null);
    }

    #[Test]
    public function anActionsOwnScopesReplaceTheResolver(): void
    {
        $refused = $this->granted('/articles/1/-actions/publish', 'articles:write', 'POST');
        self::assertSame(Response::HTTP_FORBIDDEN, $refused->getStatusCode());
        self::assertSame(['articles:publish'], self::at($this->error($refused), 'meta', 'requiredScopes'));

        self::assertSame(Response::HTTP_NO_CONTENT, $this->granted('/articles/1/-actions/publish', 'articles:publish', 'POST')->getStatusCode());
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludeReachingAnUnreadableTypeIsRefused(): void
    {
        $response = $this->granted('/articles/1?include=comments', 'articles:read');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $error = $this->error($response);
        self::assertSame(['parameter' => 'include'], $error['source'] ?? null);
        self::assertSame(['requiredScopes' => ['comments:read'], 'missingScopes' => ['comments:read'], 'include' => 'comments'], $error['meta'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anIncludeTheGrantCoversIsServed(): void
    {
        $response = $this->granted('/articles/1?include=comments', 'articles:read comments:read authors:read');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertContains('comments', $this->includedTypes($response));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aDefaultIncludeTheGrantDoesNotCoverIsLeftOut(): void
    {
        $response = $this->granted('/articles/1', 'articles:read');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotContains('authors', $this->includedTypes($response));
        $document = $this->decode($response);
        self::assertSame(['type' => 'authors', 'id' => '1'], self::at($document, 'data', 'relationships', 'author', 'data'));

        $covered = $this->granted('/articles/1', 'articles:read authors:read');
        self::assertContains('authors', $this->includedTypes($covered));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelatedReadNeedsTheReadScopesOfWhatItReturns(): void
    {
        $response = $this->granted('/articles/1/comments', 'articles:read');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(['requiredScopes' => ['articles:read', 'comments:read'], 'missingScopes' => ['comments:read']], $this->error($response)['meta'] ?? null);

        self::assertSame(Response::HTTP_OK, $this->granted('/articles/1/comments', 'articles:read comments:read')->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->granted('/articles/1/relationships/author', 'articles:read')->getStatusCode());
    }

    #[Test]
    #[Group('spec:atomic')]
    public function theAtomicEndpointNeedsItsOwnScope(): void
    {
        $response = $this->atomic([$this->addArticle('Scoped one')], 'articles:write');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(['batch'], self::at($this->error($response), 'meta', 'requiredScopes'));
        self::assertStringContainsString('ext="https://jsonapi.org/ext/atomic"', (string) $response->headers->get('Content-Type'));
        self::assertSame('Bearer error="insufficient_scope", scope="batch"', $response->headers->get('WWW-Authenticate'));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aBatchWithOneUnscopedOperationWritesNothing(): void
    {
        $before = $this->articleCount();

        $response = $this->atomic([
            $this->addArticle('Scoped one'),
            ['op' => 'remove', 'ref' => ['type' => 'articles', 'id' => '2']],
        ], 'batch articles:write');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $error = $this->error($response);
        self::assertSame(['pointer' => '/atomic:operations/1'], $error['source'] ?? null);
        self::assertSame(['articles:admin'], self::at($error, 'meta', 'missingScopes'));
        self::assertStringContainsString('ext="https://jsonapi.org/ext/atomic"', (string) $response->headers->get('Content-Type'));
        self::assertSame($before, $this->articleCount());
        self::assertSame(Response::HTTP_OK, $this->handle('/articles/2')->getStatusCode());
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aBatchTheGrantCoversIsApplied(): void
    {
        $before = $this->articleCount();

        $response = $this->atomic([$this->addArticle('Scoped one')], 'batch articles:write');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame($before + 1, $this->articleCount());
    }

    #[Test]
    public function theWarmerCachesEachServersTable(): void
    {
        $file = $this->cacheFile();
        self::assertFileExists($file);

        $table = $this->cachedTable($file);
        self::assertSame(['articles:write', 'articles:admin'], $table->for('articles', 'delete'));
        self::assertSame(['articles:read', 'authors:read'], $table->for('articles', 'fetchRelated.author'));
        self::assertSame(['batch'], $table->atomic);
    }

    #[Test]
    public function aTableMissingAnOperationResolvesItLiveAndWarns(): void
    {
        $file = $this->cacheFile();
        $original = (string) \file_get_contents($file);
        $table = $this->cachedTable($file);
        $types = $table->types;
        unset($types['articles']['create']);
        \file_put_contents($file, '<?php return ' . \var_export(['default' => (new ScopeTable($types, $table->atomic))->toArray()], true) . ';');

        try {
            self::ensureKernelShutdown();
            self::bootKernel(['debug' => false]);
            $this->afterBoot();

            $response = $this->granted('/articles', 'articles:read', 'POST', ['data' => ['type' => 'articles', 'attributes' => ['title' => 'Stale table']]]);

            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
            self::assertSame(['articles:write'], self::at($this->error($response), 'meta', 'missingScopes'));
            $logger = static::getContainer()->get('logger');
            self::assertInstanceOf(RecordingLogger::class, $logger);
            self::assertContains('The scope table has no entry for "create" on type "articles"; resolved it now. Rebuild the table.', $logger->warnings);
        } finally {
            \file_put_contents($file, $original);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function granted(string $path, string $scopes, string $method = 'GET', ?array $body = null): Response
    {
        return $this->handle($path, $method, $body, ['HTTP_' . \strtoupper(\str_replace('-', '_', HeaderGrantedScopes::HEADER)) => $scopes]);
    }

    private function cacheFile(): string
    {
        $cacheDir = static::getContainer()->getParameter('kernel.cache_dir');
        self::assertIsString($cacheDir);

        return ScopeTableProvider::path($cacheDir);
    }

    private function cachedTable(string $file): ScopeTable
    {
        $tables = require $file;
        $default = \is_array($tables) ? ($tables['default'] ?? null) : null;
        self::assertIsArray($default);

        return ScopeTable::fromArray($default);
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    protected function atomic(array $operations, string $scopes): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $request = Request::create('/operations', 'POST', server: [
            'CONTENT_TYPE' => self::ATOMIC,
            'HTTP_ACCEPT' => self::ATOMIC,
            'HTTP_X_GRANTED_SCOPES' => $scopes,
        ], content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function addArticle(string $title): array
    {
        return ['op' => 'add', 'data' => ['type' => 'articles', 'attributes' => ['title' => $title]]];
    }

    private function articleCount(): int
    {
        $data = $this->decode($this->handle('/articles?page[size]=100'))['data'] ?? null;
        self::assertIsArray($data);

        return \count($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function error(Response $response): array
    {
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);

        /** @var array<string, mixed> $error */
        return $error;
    }

    /**
     * @return list<string>
     */
    private function includedTypes(Response $response): array
    {
        $included = $this->decode($response)['included'] ?? [];
        self::assertIsArray($included);

        return \array_values(\array_map(static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['type'] ?? null) ? $resource['type'] : '', $included));
    }

    /**
     * The value at `$path` inside `$value`, or null where the path runs out.
     */
    private static function at(mixed $value, int|string ...$path): mixed
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
