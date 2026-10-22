<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\RouteCheck\RouteCheckTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * `JsonApiRoute::matches()` from a `kernel.request` listener: `true` on a JSON:API route
 * once the RouterListener has run, `false` before it and on any other route.
 */
final class JsonApiRouteTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return RouteCheckTestKernel::class;
    }

    #[Test]
    public function aListenerAfterTheRouterRecognisesAJsonApiRoute(): void
    {
        $response = $this->handle('/api/books/1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->headers->get('X-After-Routing'));
    }

    #[Test]
    public function aListenerBeforeTheRouterCannotTellYet(): void
    {
        self::assertSame('no', $this->handle('/api/books/1')->headers->get('X-Before-Routing'));
    }

    #[Test]
    public function aPlainApplicationRouteIsNotJsonApi(): void
    {
        $response = $this->handle('/plain');

        self::assertSame('plain', $response->getContent());
        self::assertSame('no', $response->headers->get('X-After-Routing'));
    }
}
