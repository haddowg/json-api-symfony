<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DependencyInjection;

use haddowg\JsonApiBundle\JsonApiBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How `json_api.output_timezone` and `json_api.servers.<name>.output_timezone` reach
 * each server's {@see \haddowg\JsonApiBundle\Server\ServerFactory}, and that a zone
 * PHP does not recognise fails the container build.
 */
#[Group('spec:multi-server')]
final class OutputTimezoneConfigTest extends TestCase
{
    #[Test]
    public function anAbsentZoneLeavesEveryServerWithoutOne(): void
    {
        $container = $this->load(['servers' => ['admin' => []]]);

        self::assertNull($this->outputTimezoneOf($container, 'default'));
        self::assertNull($this->outputTimezoneOf($container, 'admin'));
    }

    #[Test]
    public function aNamedServerInheritsTheTopLevelZoneUnlessItSetsItsOwn(): void
    {
        $container = $this->load([
            'output_timezone' => 'UTC',
            'servers' => [
                'absent' => [],
                'null' => ['output_timezone' => null],
                'tokyo' => ['output_timezone' => 'Asia/Tokyo'],
                'off' => ['output_timezone' => ''],
            ],
        ]);

        self::assertSame('UTC', $this->outputTimezoneOf($container, 'default'));
        self::assertSame('UTC', $this->outputTimezoneOf($container, 'absent'));
        self::assertSame('UTC', $this->outputTimezoneOf($container, 'null'));
        self::assertSame('Asia/Tokyo', $this->outputTimezoneOf($container, 'tokyo'));
        self::assertNull($this->outputTimezoneOf($container, 'off'));
    }

    #[Test]
    public function anEmptyTopLevelZoneMeansNoConversion(): void
    {
        $container = $this->load(['output_timezone' => '', 'servers' => ['admin' => []]]);

        self::assertNull($this->outputTimezoneOf($container, 'default'));
        self::assertNull($this->outputTimezoneOf($container, 'admin'));
    }

    #[Test]
    public function aNamedServerCanSetAZoneWithoutATopLevelOne(): void
    {
        $container = $this->load(['servers' => ['tokyo' => ['output_timezone' => 'Asia/Tokyo']]]);

        self::assertNull($this->outputTimezoneOf($container, 'default'));
        self::assertSame('Asia/Tokyo', $this->outputTimezoneOf($container, 'tokyo'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidZones(): iterable
    {
        yield 'top-level typo' => [['output_timezone' => 'Europe/Londn'], 'json_api.output_timezone'];
        yield 'top-level non-string' => [['output_timezone' => 5], 'json_api.output_timezone'];
        yield 'named server non-string' => [['servers' => ['tokyo' => ['output_timezone' => true]]], 'json_api.servers.tokyo.output_timezone'];
        yield 'named server typo' => [['servers' => ['tokyo' => ['output_timezone' => 'Asia/Tokio']]], 'json_api.servers.tokyo.output_timezone'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('invalidZones')]
    public function anUnrecognisedZoneFailsTheBuild(array $config, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . \preg_quote($path, '/') . '.*is not a timezone PHP recognises/s');

        $this->load($config);
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

    private function outputTimezoneOf(ContainerBuilder $container, string $server): mixed
    {
        return $container->getDefinition(JsonApiBundle::serverFactoryId($server))->getArgument('$outputTimezone');
    }
}
