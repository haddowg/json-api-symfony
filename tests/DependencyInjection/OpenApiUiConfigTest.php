<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DependencyInjection;

use haddowg\JsonApiBundle\Controller\OpenApiUiController;
use haddowg\JsonApiBundle\JsonApiBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How `json_api.openapi.ui.redoc_version` and `ui.redoc_telemetry` reach the viewer.
 */
#[Group('spec:openapi')]
final class OpenApiUiConfigTest extends TestCase
{
    #[Test]
    public function redocDefaultsToThreeXWithTelemetryOff(): void
    {
        $controller = $this->load([])->getDefinition(OpenApiUiController::class);

        self::assertSame('3.0.1', $controller->getArgument('$redocVersion'));
        self::assertFalse($controller->getArgument('$redocTelemetry'));
    }

    #[Test]
    public function theVersionAndTelemetryAreConfigurable(): void
    {
        $controller = $this->load(['openapi' => ['ui' => [
            'renderer' => 'redoc',
            'redoc_version' => '2.5.4',
            'redoc_telemetry' => true,
        ]]])->getDefinition(OpenApiUiController::class);

        self::assertSame('2.5.4', $controller->getArgument('$redocVersion'));
        self::assertTrue($controller->getArgument('$redocTelemetry'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'a range' => ['^3.0'];
        yield 'a major only' => ['3'];
        yield 'an unsupported major' => ['1.0.0'];
        yield 'a tag' => ['latest'];
        yield 'not a string' => [3];
    }

    #[Test]
    #[DataProvider('invalidVersions')]
    public function anInexactOrUnsupportedVersionFailsTheBuild(mixed $version): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/redoc_version must be an exact 2.x or 3.x version/');

        $this->load(['openapi' => ['ui' => ['redoc_version' => $version]]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.project_dir', \sys_get_temp_dir());
        $container->setParameter('kernel.bundles', []);

        $extension = (new JsonApiBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
