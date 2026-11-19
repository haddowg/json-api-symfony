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

    protected function afterBoot(): void
    {
        TypedRequestMetaInMemoryTestKernel::reset();
    }

    protected function bootInDebug(): void
    {
        static::ensureKernelShutdown();
        static::bootKernel(['debug' => true]);
        $this->afterBoot();
    }

    protected function sourceOf(string $id): ?string
    {
        $note = TypedRequestMetaInMemoryTestKernel::provider('tr-notes')->fetchOne('tr-notes', $id);

        return $note instanceof TrNote ? $note->source : null;
    }
}
