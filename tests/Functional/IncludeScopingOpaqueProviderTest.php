<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping\IncludeScopingInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `opaque-` types are served by a provider that neither declares its scope nor
 * batches, so the bundle treats them as scoped and loads their rows through it, per
 * parent and member type, rather than off the model (bundle ADR 0125).
 */
final class IncludeScopingOpaqueProviderTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return IncludeScopingInMemoryTestKernel::class;
    }

    #[Test]
    public function anIncludeLoadsThroughTheProviderPerParent(): void
    {
        $response = $this->handle('/opaque-advertisers/1?include=agencies');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2'], $this->includedIds($this->decode($response)));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function includedIds(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        return \array_values(\array_map(static fn(mixed $resource): string => \is_array($resource) && \is_string($resource['id'] ?? null) ? $resource['id'] : '', $included));
    }
}
