<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Scopes\ScopesInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the scope table build refuses, what the OpenAPI document and its strict export say
 * about scopes, and that a server without a grant provider enforces nothing.
 */
#[Group('scopes')]
final class ScopesBuildTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return ScopesInMemoryTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        $this->restoreHandlers();
    }

    #[Test]
    public function aPublicOperationGivenAScopeFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The operation "fetchOne" of type "authors" is public but is given the scopes authors:read.');

        $this->boot('public_scoped');
    }

    #[Test]
    public function anOverrideForARelationTheTypeDoesNotHaveFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The type "comments" declares scopes for the operation "fetchRelated.article", which it does not have.');

        $this->boot('unknown_relation');
    }

    #[Test]
    #[Group('openapi')]
    public function eachScopedOperationNamesItsScopesUnderTheScopeScheme(): void
    {
        $this->boot('test');
        $document = $this->document();

        self::assertSame([['oauth2' => ['articles:write', 'articles:admin']]], self::at($document, 'paths', '/articles/{id}', 'delete', 'security'));
        self::assertSame([['oauth2' => ['articles:read', 'comments:read']]], self::at($document, 'paths', '/articles/{id}/comments', 'get', 'security'));
        self::assertSame([['oauth2' => ['articles:publish']]], self::at($document, 'paths', '/articles/{id}/-actions/publish', 'post', 'security'));
        self::assertSame([['oauth2' => ['batch']]], self::at($document, 'paths', '/operations', 'post', 'security'));
        $responses = self::at($document, 'paths', '/articles/{id}', 'delete', 'responses');
        self::assertIsArray($responses);
        self::assertArrayHasKey('403', $responses);

        $flow = self::at($document, 'components', 'securitySchemes', 'oauth2', 'flows', 'clientCredentials');
        self::assertIsArray($flow);
        self::assertSame('Read comments.', self::at($flow, 'scopes', 'comments:read'));
        self::assertSame('https://example.test/oauth/token', $flow['tokenUrl'] ?? null);
    }

    #[Test]
    #[Group('openapi')]
    public function theStrictExportPassesWhenEveryScopeIsDeclared(): void
    {
        $this->boot('test');
        $tester = $this->export();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('comments:read', $tester->getErrorOutput());
    }

    #[Test]
    #[Group('openapi')]
    public function theStrictExportFailsOnAnUndeclaredScope(): void
    {
        $this->boot('undeclared');
        $tester = $this->export();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $errors = (string) \preg_replace('/\s+/', ' ', $tester->getErrorOutput());
        self::assertStringContainsString('The scope "comments:read" is required by', $errors);
        self::assertStringContainsString('--strict fails on an undeclared scope', $errors);
        self::assertSame('', $tester->getDisplay());
    }

    #[Test]
    #[Group('openapi')]
    public function theExportWarnsWhenTheScopeSchemeIsNotOAuth2(): void
    {
        $this->boot('bearer_scheme');
        $tester = $this->export(strict: false);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('listed under the "bearer" security scheme, which is http, not oauth2', (string) \preg_replace('/\s+/', ' ', $tester->getErrorOutput()));
    }

    #[Test]
    public function withoutAGrantProviderNothingIsEnforced(): void
    {
        $this->boot('unenforced');
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $response = $kernel->handle(Request::create('/articles/1', server: [
            'HTTP_ACCEPT' => 'application/vnd.api+json',
            'HTTP_X_GRANTED_SCOPES' => 'nothing',
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([['oauth2' => ['articles:read']]], self::at($this->document(), 'paths', '/articles/{id}', 'get', 'security'));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);

        /** @var array<string, mixed> */
        return \json_decode($factory->forServer()->toJsonString(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function export(bool $strict = true): CommandTester
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('json-api:openapi:export'));
        $file = \sys_get_temp_dir() . '/json-api-symfony-tests/scopes-export.json';
        $tester->execute(['--output' => $file] + ($strict ? ['--strict' => true] : []), ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function boot(string $environment): void
    {
        static::ensureKernelShutdown();
        $this->removeDir((new ScopesInMemoryTestKernel($environment, false))->getCacheDir());
        static::bootKernel(['environment' => $environment, 'debug' => false]);
    }

    private function restoreHandlers(): void
    {
        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->errorHandler) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->exceptionHandler) {
                break;
            }
            \restore_exception_handler();
        }
    }

    private function removeDir(string $dir): void
    {
        $root = \realpath(\sys_get_temp_dir() . '/json-api-symfony-tests');
        $target = \realpath($dir);
        if ($root === false || $target === false || !\str_starts_with($target, $root . '/')) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isDir()) {
                @\rmdir($file->getPathname());
            } else {
                @\unlink($file->getPathname());
            }
        }

        @\rmdir($target);
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
