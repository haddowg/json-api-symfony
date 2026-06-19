<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiUiDisabledTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The viewer gating witness (design D6): `ui.enabled: false` suppresses the UI route
 * while exposure stays on, so the document is still served but the viewer is not — the
 * viewer is independently switchable from the document. (The shared expose gate
 * suppressing *both* routes is covered by {@see OpenApiExposeAndMultiServerTest}.)
 */
final class OpenApiUiGateTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenApiUiDisabledTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theUiRouteIsSuppressedWhenDisabled(): void
    {
        // The document is exposed...
        self::assertSame(200, $this->handle('/docs.json')->getStatusCode());
        // ...but the viewer route is not registered.
        self::assertSame(404, $this->handle('/docs')->getStatusCode());
    }
}
