<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\OpenApiServe;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\WarningLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * With the value's class in `json_api.openapi.serve_allowed_classes` the warmer caches the
 * document for serve decorators as usual (bundle ADR 0077).
 */
final class OpenApiServeAllowedObjectValuesTest extends OpenApiServeTestCase
{
    protected static function environment(): string
    {
        return 'objects_allowed';
    }

    protected function setUp(): void
    {
        parent::setUp();

        WarningLogger::$warnings = [];
    }

    #[Test]
    #[Group('spec:openapi')]
    public function anAllowListedClassIsCached(): void
    {
        $this->warm();

        self::assertSame([], WarningLogger::$warnings);
        $artifact = $this->store()->readServeArtifact('default');
        self::assertNotNull($artifact);
        self::assertInstanceOf(\DateTimeImmutable::class, $artifact->document->info->extension('x-released'));

        $response = $this->get('/docs.json', 'one.test');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('2026-01-01 00:00:00.000000', self::arr(self::arr($this->json($response)['info'] ?? null)['x-released'] ?? null)['date'] ?? null);
    }
}
