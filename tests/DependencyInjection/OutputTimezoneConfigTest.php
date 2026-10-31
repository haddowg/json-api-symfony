<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DependencyInjection;

use haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\GigResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\SharedGigResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * How `json_api.output_timezone` and `json_api.servers.<name>.output_timezone` reach
 * each server's {@see \haddowg\JsonApiBundle\Server\ServerFactory}, that a zone PHP
 * does not recognise fails the container build, and that a class on several servers
 * gets one service per server.
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

    #[Test]
    public function aClassOnSeveralServersGetsACopyPerServerAndASingleServerClassKeepsItsService(): void
    {
        $container = $this->load(['servers' => ['tokyo' => [], 'raw' => []]]);
        $container->register(SharedGigResource::class, SharedGigResource::class)
            ->addTag(JsonApiBundle::RESOURCE_TAG, ['server' => 'tokyo,raw']);
        $container->register(GigResource::class, GigResource::class)
            ->addTag(JsonApiBundle::RESOURCE_TAG, ['server' => 'tokyo']);

        (new ResourceLocatorPass())->process($container);

        $tokyo = $this->servicesOf($container, 'tokyo');
        $raw = $this->servicesOf($container, 'raw');

        self::assertSame('.haddowg.json_api.server_resource.tokyo.' . SharedGigResource::class, $tokyo[SharedGigResource::class]);
        self::assertSame('.haddowg.json_api.server_resource.raw.' . SharedGigResource::class, $raw[SharedGigResource::class]);
        self::assertSame(GigResource::class, $tokyo[GigResource::class]);

        $copy = $container->getDefinition($tokyo[SharedGigResource::class]);
        self::assertSame(SharedGigResource::class, $copy->getClass());
        self::assertSame([], $copy->getTags());
    }

    /**
     * The service id behind each class-string of a server's resource locator.
     *
     * @return array<string, string>
     */
    private function servicesOf(ContainerBuilder $container, string $server): array
    {
        $locator = $container->getDefinition(JsonApiBundle::serverFactoryId($server))->getArgument('$resources');
        self::assertInstanceOf(Definition::class, $locator);
        $services = $locator->getArgument(0);
        self::assertInstanceOf(Reference::class, $services);
        $map = $container->getDefinition((string) $services)->getArgument(0);
        self::assertIsArray($map);

        $ids = [];
        foreach ($map as $class => $argument) {
            self::assertInstanceOf(ServiceClosureArgument::class, $argument);
            $reference = $argument->getValues()[0] ?? null;
            self::assertInstanceOf(Reference::class, $reference);
            $ids[(string) $class] = (string) $reference;
        }

        return $ids;
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
