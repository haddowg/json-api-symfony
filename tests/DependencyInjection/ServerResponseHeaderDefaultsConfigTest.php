<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DependencyInjection;

use haddowg\JsonApiBundle\Http\ResponseHeadersRegistry;
use haddowg\JsonApiBundle\JsonApiBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How `json_api.servers.<name>.defaults` reaches the {@see ResponseHeadersRegistry}
 * (bundle ADR 0164), and that it rejects what the global `json_api.defaults` rejects.
 */
final class ServerResponseHeaderDefaultsConfigTest extends TestCase
{
    #[Test]
    public function aServersOwnDefaultsReachTheRegistryKeyedByServer(): void
    {
        $registry = $this->load([
            'defaults' => ['deprecation' => true],
            'servers' => [
                'v5' => ['defaults' => [
                    'sunset' => 'Sat, 01 May 2027 00:00:00 GMT',
                    'sunset_link' => 'https://example.test/v6',
                    'cache_headers' => ['max_age' => 30],
                ]],
                'v6' => [],
            ],
        ])->getDefinition(ResponseHeadersRegistry::class);

        self::assertSame(['deprecation' => true], $registry->getArgument('$defaultDeprecation'));
        self::assertSame(['v5' => [
            'cache' => ['max_age' => 30],
            'deprecation' => ['sunset' => 'Sat, 01 May 2027 00:00:00 GMT', 'sunset_link' => 'https://example.test/v6'],
        ]], $registry->getArgument('$serverDefaults'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badDefaults(): iterable
    {
        yield 'a non-integer max_age' => [['cache_headers' => ['max_age' => 'soon']], 'max_age'];
        yield 'a non-boolean public' => [['cache_headers' => ['public' => 'yes']], 'public'];
        yield 'an unknown cache directive' => [['cache_headers' => ['stale' => 1]], 'stale'];
        yield 'a numeric deprecation' => [['deprecation' => 1688169599], 'deprecation default must be'];
        yield 'a numeric sunset' => [['sunset' => 1688169599], 'sunset default must be'];
        yield 'a numeric sunset_link' => [['sunset_link' => 6], 'sunset_link default must be'];
        yield 'an unknown key' => [['expires' => 'never'], 'expires'];
    }

    /**
     * @param array<string, mixed> $defaults
     */
    #[Test]
    #[DataProvider('badDefaults')]
    public function aBadServerDefaultFailsTheBuild(array $defaults, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . \preg_quote($message, '/') . '/');

        $this->load(['servers' => ['v5' => ['defaults' => $defaults]]]);
    }

    /**
     * @param array<string, mixed> $defaults
     */
    #[Test]
    #[DataProvider('badDefaults')]
    public function theSameBadGlobalDefaultFailsTheBuild(array $defaults, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . \preg_quote($message, '/') . '/');

        $this->load(['defaults' => $defaults]);
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
