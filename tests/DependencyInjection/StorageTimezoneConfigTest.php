<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DependencyInjection;

use haddowg\JsonApiBundle\DataProvider\Doctrine\NaiveDateTimeStorage;
use haddowg\JsonApiBundle\JsonApiBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How `json_api.storage_timezone` reaches the Doctrine data layer's date storage.
 */
final class StorageTimezoneConfigTest extends TestCase
{
    #[Test]
    public function anAbsentZoneLeavesTheDefaultTimezoneInCharge(): void
    {
        self::assertNull($this->load([])->getParameter('haddowg_json_api.storage_timezone'));
    }

    #[Test]
    public function anEmptyZoneMeansTheDefaultTimezone(): void
    {
        self::assertNull($this->load(['storage_timezone' => ''])->getParameter('haddowg_json_api.storage_timezone'));
    }

    #[Test]
    public function aConfiguredZoneReachesTheDateStorage(): void
    {
        $container = $this->load(['storage_timezone' => 'UTC']);

        self::assertSame('UTC', $container->getParameter('haddowg_json_api.storage_timezone'));
        self::assertSame(
            '%haddowg_json_api.storage_timezone%',
            $container->getDefinition(NaiveDateTimeStorage::class)->getArgument('$timezone'),
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidZones(): iterable
    {
        yield 'typo' => ['Europe/Londn'];
        yield 'non-string' => [5];
    }

    #[Test]
    #[DataProvider('invalidZones')]
    public function anUnrecognisedZoneFailsTheBuild(mixed $zone): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/json_api\.storage_timezone.*is not a timezone PHP recognises/s');

        $this->load(['storage_timezone' => $zone]);
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
