<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DependencyInjection\InstalledVersionsCheck;
use haddowg\JsonApiBundle\Tests\Functional\App\StaleContainer\StaleContainerTestKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A non-debug container compiled against other versions than the installed ones is
 * refused at boot.
 */
final class StaleContainerTest extends TestCase
{
    private ?StaleContainerTestKernel $kernel = null;

    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        // Booting installs Symfony's error and exception handlers; tearDown pops them.
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;

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

    #[Test]
    public function aContainerCompiledAgainstTheInstalledVersionsBoots(): void
    {
        $this->kernel = new StaleContainerTestKernel($this->cacheRoot('fresh'));
        $this->kernel->boot();

        self::assertSame(InstalledVersionsCheck::installed(), $this->kernel->getContainer()->getParameter(InstalledVersionsCheck::PARAMETER));
    }

    #[Test]
    public function aContainerCompiledAgainstAnotherVersionThrowsOnBoot(): void
    {
        $installed = InstalledVersionsCheck::installed();
        self::assertIsArray($installed);
        $installed['haddowg/json-api'] = ['version' => 'v0.0.1', 'reference' => null];

        $this->kernel = new StaleContainerTestKernel($this->cacheRoot('stale'), $installed);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('haddowg/json-api v0.0.1');
        $this->kernel->boot();
    }

    #[Test]
    public function aContainerRecordingNullReferencesBoots(): void
    {
        $installed = InstalledVersionsCheck::installed();
        self::assertIsArray($installed);
        $recorded = \array_map(
            static fn(?array $entry): ?array => $entry !== null ? ['version' => $entry['version'], 'reference' => null] : null,
            $installed,
        );

        $this->kernel = new StaleContainerTestKernel($this->cacheRoot('null-reference'), $recorded);
        $this->kernel->boot();

        self::assertSame($recorded, $this->kernel->getContainer()->getParameter(InstalledVersionsCheck::PARAMETER));
    }

    private function cacheRoot(string $variant): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/stale-container/' . $variant . '-' . \bin2hex(\random_bytes(4));
    }
}
