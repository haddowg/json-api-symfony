<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use haddowg\JsonApiBundle\Security\UnenforceableSecurityException;
use haddowg\JsonApiBundle\Tests\Functional\App\SecurityEnforcement\SecurityEnforcementKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A declared security expression with no authorization checker to evaluate it fails
 * the build instead of leaving the operation open. Resource and
 * action expressions fail the container compile; relation expressions fail the
 * warm-up that runs when a cold-cache kernel boots. The documentation-only
 * `true`/`false` values and an app with no security at all still build.
 */
final class SecurityEnforcementBuildTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return SecurityEnforcementKernel::class;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    #[Test]
    public function aResourceExpressionWithoutAFirewallFailsTheBuild(): void
    {
        $this->expectUnenforceable('type "guard-gadgets" (securityRead)');
        $this->boot('resource', firewall: false);
    }

    #[Test]
    public function aRelationExpressionWithoutAFirewallFailsTheBuild(): void
    {
        $this->expectUnenforceable('relationship "partner" on type "guard-gadgets" (security read)');
        $this->boot('relation', firewall: false);
    }

    #[Test]
    public function anActionExpressionWithoutAFirewallFailsTheBuild(): void
    {
        $this->expectUnenforceable('action "inspect" on type "guard-gadgets"');
        $this->boot('action', firewall: false);
    }

    #[Test]
    public function expressionsWithAFirewallBuild(): void
    {
        // Data providers are avoided: Foundry's extension boots the kernel while it
        // collects one, before a variant is chosen.
        foreach (['resource', 'relation', 'action'] as $variant) {
            $this->boot($variant, firewall: true);
            self::assertNotNull(static::$kernel, $variant);
        }
    }

    #[Test]
    public function documentationOnlyDeclarationsBuildWithoutAFirewall(): void
    {
        $this->boot('documented', firewall: false);

        $registry = static::getContainer()->get(ResourceSecurityRegistry::class);
        self::assertInstanceOf(ResourceSecurityRegistry::class, $registry);
        $security = $registry->securityFor('guard-gadgets');
        self::assertNotNull($security);
        self::assertTrue($security->forRead());
        self::assertFalse($security->forList());
    }

    #[Test]
    public function noDeclarationsBuildWithoutAFirewall(): void
    {
        $this->boot('none', firewall: false);

        self::assertNotNull(static::$kernel);
    }

    private function expectUnenforceable(string $declaration): void
    {
        $this->expectException(UnenforceableSecurityException::class);
        $this->expectExceptionMessage($declaration);
        $this->expectExceptionMessage('the security.authorization_checker service is not defined');
    }

    private function boot(string $variant, bool $firewall): void
    {
        static::ensureKernelShutdown();
        SecurityEnforcementKernel::$variant = $variant;
        SecurityEnforcementKernel::$firewall = $firewall;
        $kernel = new SecurityEnforcementKernel('test', false);
        $this->removeDir($kernel->getCacheDir());
        static::bootKernel(['debug' => false]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        $this->restoreHandlers();
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
        if (!\is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
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

        @\rmdir($dir);
    }
}
