<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource;

use Doctrine\DBAL\Logging\Middleware;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;

/**
 * The id-source app with every SQL statement recorded, and on DBAL 3 with nested
 * transactions that don't use savepoints, the setting under which a failed insert inside a
 * transaction leaves nothing to roll back to.
 */
final class SqlRecordingIdSourceTestKernel extends IdSourceTestKernel
{
    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/id-source-sql-cache/' . $this->environment;
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        parent::configureContainer($container, $loader, $builder);

        if (\str_starts_with((string) \Composer\InstalledVersions::getVersion('doctrine/dbal'), '3.')) {
            $container->extension('doctrine', ['dbal' => ['use_savepoints' => false]]);
        }

        $container->services()
            ->set('test.sql_recording_middleware', Middleware::class)
            ->args([inline_service(RecordingSqlLogger::class)])
            ->tag('doctrine.middleware');
    }
}
