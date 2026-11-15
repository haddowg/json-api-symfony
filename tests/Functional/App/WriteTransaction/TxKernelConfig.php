<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

/**
 * The configuration both write-transaction kernels share: Atomic Operations on, and the
 * hooks and actions the suite drives.
 */
final class TxKernelConfig
{
    public static function apply(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);
        PinnedConfigDefaults::apply($container, $builder);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
            'atomic_operations' => ['enabled' => true],
        ]);

        $builder->register('logger', TxLogger::class);
    }

    public static function services(DefaultsConfigurator $services): void
    {
        $services->set(TxSideWriter::class);
        $services->set(TxCallbacks::class);
        $services->set(TxHookSubscriber::class);
        $services->set(StampTxWidget::class);
        $services->set(SweepTxWidgets::class);
        $services->set(PeekTxWidget::class);
    }
}
