<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\OpenApiServe;

use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\FeatureGateDecorator;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\WarningLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A document whose values hold an object outside the serve artifact's allow-list: the
 * warmer logs a warning naming the class and the config key, writes no artifact, and
 * decorated requests build the document instead.
 */
final class OpenApiServeObjectValuesTest extends OpenApiServeTestCase
{
    protected static function environment(): string
    {
        return 'objects';
    }

    protected function setUp(): void
    {
        parent::setUp();

        WarningLogger::$warnings = [];
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theWarmerWarnsAndSkipsTheArtifact(): void
    {
        $this->warm();

        self::assertCount(1, WarningLogger::$warnings);
        self::assertStringContainsString('"default"', WarningLogger::$warnings[0]);
        self::assertStringContainsString('DateTimeImmutable', WarningLogger::$warnings[0]);
        self::assertStringContainsString('json_api.openapi.serve_allowed_classes', WarningLogger::$warnings[0]);

        self::assertNull($this->store()->readServeArtifact('default'));
        self::assertIsString($this->store()->read('default'), 'the JSON artifact is still warmed');
    }

    #[Test]
    #[Group('spec:openapi')]
    public function decoratedRequestsFallBackToBuildingTheDocument(): void
    {
        $this->warm();

        $response = $this->get('/docs.json', 'one.test');

        self::assertSame(200, $response->getStatusCode());
        $this->assertValidOpenApi($response);
        $document = $this->json($response);
        self::assertSame([['url' => 'https://one.test/api']], $document['servers'] ?? null);
        self::assertArrayHasKey('x-released', self::arr($document['info'] ?? null));
        self::assertArrayNotHasKey('/categories', self::arr($document['paths'] ?? null));
        self::assertSame(1, FeatureGateDecorator::$decorated);
    }
}
