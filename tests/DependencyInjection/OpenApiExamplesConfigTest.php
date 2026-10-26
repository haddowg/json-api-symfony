<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DependencyInjection;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How `json_api.openapi.examples`, and a named server's own `openapi_examples`, reach
 * the {@see MetadataSource}.
 */
#[Group('spec:openapi')]
final class OpenApiExamplesConfigTest extends TestCase
{
    #[Test]
    public function examplesAreOnByDefaultOnEveryServer(): void
    {
        self::assertSame(['default' => true, 'admin' => true], $this->exampleGeneration(['servers' => ['admin' => []]]));
    }

    #[Test]
    public function aNamedServerInheritsTheTopLevelSettingUnlessItSetsItsOwn(): void
    {
        self::assertSame(
            ['default' => false, 'absent' => false, 'null' => false, 'on' => true],
            $this->exampleGeneration([
                'openapi' => ['examples' => false],
                'servers' => [
                    'absent' => [],
                    'null' => ['openapi_examples' => null],
                    'on' => ['openapi_examples' => true],
                ],
            ]),
        );

        self::assertSame(
            ['default' => true, 'off' => false],
            $this->exampleGeneration(['servers' => ['off' => ['openapi_examples' => false]]]),
        );
    }

    #[Test]
    public function aNonBooleanServerSettingFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/openapi_examples must be true, false or null/');

        $this->exampleGeneration(['servers' => ['admin' => ['openapi_examples' => 'yes']]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function exampleGeneration(array $config): mixed
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

        return $container->getDefinition(MetadataSource::class)->getArgument('$exampleGeneration');
    }
}
