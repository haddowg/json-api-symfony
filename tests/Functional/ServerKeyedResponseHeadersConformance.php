<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\ResourcesOnServerPass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The same type names served by a different resource class on each of two servers,
 * each class with its own response headers, over global defaults of
 * a public 120s cache and `Deprecation: true`. `cachedWidgets` is public for 60s on the
 * named server and private for 10s on the default one; `plainWidgets` declares nothing
 * on the named server and a private 15s cache on the default one. Widget 1 is seeded.
 *
 * @phpstan-require-extends JsonApiFunctionalTestCase
 */
trait ServerKeyedResponseHeadersConformance
{
    #[Test]
    public function eachServerEmitsItsOwnClassesCacheHeaders(): void
    {
        $default = $this->handle('/cachedWidgets/1');
        self::assertSame(200, $default->getStatusCode(), (string) $default->getContent());
        self::assertSame(10, $default->getMaxAge());
        self::assertTrue($default->headers->hasCacheControlDirective('private'));
        self::assertFalse($default->headers->hasCacheControlDirective('public'));

        $named = $this->handle('/' . ResourcesOnServerPass::SERVER . '/cachedWidgets/1');
        self::assertSame(200, $named->getStatusCode(), (string) $named->getContent());
        self::assertSame(60, $named->getMaxAge());
        self::assertTrue($named->headers->hasCacheControlDirective('public'));
        self::assertFalse($named->headers->hasCacheControlDirective('private'));
    }

    #[Test]
    public function aServerWhoseClassDeclaresNothingGetsTheDefaults(): void
    {
        $named = $this->handle('/' . ResourcesOnServerPass::SERVER . '/plainWidgets/1');
        self::assertSame(200, $named->getStatusCode(), (string) $named->getContent());
        self::assertSame(120, $named->getMaxAge());
        self::assertTrue($named->headers->hasCacheControlDirective('public'));
        self::assertFalse($named->headers->hasCacheControlDirective('private'));
        self::assertTrue($named->headers->has('Deprecation'));

        $default = $this->handle('/plainWidgets/1');
        self::assertSame(200, $default->getStatusCode(), (string) $default->getContent());
        self::assertSame(15, $default->getMaxAge());
        self::assertTrue($default->headers->hasCacheControlDirective('private'));
    }
}
