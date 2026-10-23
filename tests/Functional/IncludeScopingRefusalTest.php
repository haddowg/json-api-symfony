<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * A relation whose related reads are scoped, but which cannot be loaded through its
 * provider, is refused with a `500` rather than rendered from the model (bundle ADR
 * 0125). The `opaque-` types are served by a provider that neither declares its scope
 * nor batches.
 */
final class IncludeScopingRefusalTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return IncludeScopingInMemoryTestKernel::class;
    }

    #[Test]
    public function anIncludeTheProviderCannotLoadIsRefused(): void
    {
        $this->assertRefused('/opaque-advertisers/1?include=agencies');
    }

    #[Test]
    public function linkageDataTheProviderCannotLoadIsRefused(): void
    {
        // Without a load-state predicate every relation renders its linkage data.
        $this->assertRefused('/opaque-advertisers/1');
    }

    #[Test]
    public function aPolymorphicRelationReachingAScopedTypeIsRefused(): void
    {
        $this->assertRefused('/opaque-advertisers/1/relationships/mixedAgencies');
    }

    private function assertRefused(string $path): void
    {
        $response = $this->handle($path);

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringNotContainsString('Soft Hidden', (string) $response->getContent());
    }
}
