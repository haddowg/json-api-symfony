<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter\ActionFilterInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Test;

final class InMemoryActionFilterTest extends ActionFilterConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ActionFilterInMemoryTestKernel::class;
    }

    #[Test]
    public function theInMemoryProviderOffersNoNativeQuery(): void
    {
        $meta = $this->meta('/filterCrates/-actions/tally');
        self::assertNull($meta['native'] ?? null);
        self::assertNull($meta['queried'] ?? null);
    }
}
