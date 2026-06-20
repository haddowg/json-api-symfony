<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation\EagerValidationDoctrineKernel;

/**
 * {@see EagerLoadValidationConformanceTestCase} against the Doctrine kernel (bundle
 * ADR 0085): proves the boot-time eager-load validation throws / accepts identically
 * in a Doctrine-configured app — the rule is provider-agnostic metadata, so a
 * malformed `on()` chain fails the build on either provider.
 */
final class DoctrineEagerLoadValidationTest extends EagerLoadValidationConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return EagerValidationDoctrineKernel::class;
    }

    protected function bootWithSubject(string $subject): void
    {
        static::ensureKernelShutdown();
        EagerValidationDoctrineKernel::$subjectResource = $subject;
        // A fresh cold-cache boot so the non-optional eager-load warmer re-runs.
        $kernel = new EagerValidationDoctrineKernel('test', false);
        self::removeDir($kernel->getCacheDir());
        static::bootKernel(['debug' => false]);
    }
}
