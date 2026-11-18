<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta\TypedMetaInMemoryTestKernel;

final class InMemoryTypedDocumentMetaTest extends TypedDocumentMetaConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return TypedMetaInMemoryTestKernel::class;
    }

    protected function afterBoot(): void
    {
        TypedMetaInMemoryTestKernel::reset();
    }

    protected function bootInDebug(): void
    {
        static::ensureKernelShutdown();
        static::bootKernel(['debug' => true]);
        $this->afterBoot();
    }
}
