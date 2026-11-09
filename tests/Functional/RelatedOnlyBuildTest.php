<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RelatedOnly\ArchiveImprint;
use haddowg\JsonApiBundle\Tests\Functional\App\RelatedOnly\ReimprintRecord;
use haddowg\JsonApiBundle\Tests\Functional\App\RelatedOnly\RelatedOnlyTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A custom action mounted on a related-only type fails the container build, naming it.
 */
final class RelatedOnlyBuildTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return RelatedOnlyTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    #[Test]
    public function anActionMountedOnARelatedOnlyTypeFailsTheBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf(
            'The JSON:API action "archive" on service "%s" is mounted on type "imprints", which is related-only',
            ArchiveImprint::class,
        ));

        static::ensureKernelShutdown();
        RelatedOnlyTestKernel::$actions = [ArchiveImprint::class];
        static::bootKernel(['debug' => false]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        RelatedOnlyTestKernel::$actions = [ReimprintRecord::class];

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
}
