<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta\TrNote;
use haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta\TypedRequestMetaInMemoryTestKernel;

final class InMemoryTypedRequestMetaTest extends TypedRequestMetaConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return TypedRequestMetaInMemoryTestKernel::class;
    }

    /**
     * Seeds the stores before the kernel boots: a cold container's cache warm-up already
     * builds the providers, which would keep serving stores seeded before a later reset.
     */
    protected function setUp(): void
    {
        TypedRequestMetaInMemoryTestKernel::reset();
        parent::setUp();
    }

    protected function bootInDebug(): void
    {
        static::ensureKernelShutdown();
        TypedRequestMetaInMemoryTestKernel::reset();
        static::bootKernel(['debug' => true]);
    }

    protected function sourceOf(string $id): ?string
    {
        $note = TypedRequestMetaInMemoryTestKernel::provider('tr-notes')->fetchOne('tr-notes', $id);

        return $note instanceof TrNote ? $note->source : null;
    }
}
