<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use haddowg\JsonApiBundle\Tests\Functional\App\Atomic\AtomicInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * `JsonApiRoute::of()` on the Atomic Operations endpoint, which addresses no type.
 */
final class JsonApiRouteOfAtomicTest extends JsonApiRouteOfTestCase
{
    protected static function getKernelClass(): string
    {
        return AtomicInMemoryTestKernel::class;
    }

    #[Test]
    public function theAtomicRoute(): void
    {
        $route = $this->route('POST', '/operations');

        self::assertEquals(new JsonApiRoute('default', isAtomic: true), $route);
        self::assertNull($route->target());
    }
}
