<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation\EagerValidationInMemoryKernel;

/**
 * {@see EagerLoadValidationConformanceTestCase} against the in-memory kernel (bundle
 * ADR 0085): the boot-time eager-load validation throws / accepts for each pinned
 * shape identically to the Doctrine witness — the rule is provider-agnostic metadata.
 */
final class InMemoryEagerLoadValidationTest extends EagerLoadValidationConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return EagerValidationInMemoryKernel::class;
    }

    protected function bootWithSubject(string $subject): void
    {
        static::ensureKernelShutdown();
        EagerValidationInMemoryKernel::$subjectResource = $subject;
        // A fresh cold-cache boot so the non-optional eager-load warmer re-runs (a prior
        // run that threw never completed warm-up, but clear the per-subject cache dir to
        // make the cold boot deterministic across separate test invocations).
        $kernel = new EagerValidationInMemoryKernel('test', false);
        self::removeDir($kernel->getCacheDir());
        static::bootKernel(['debug' => false]);
    }
}
