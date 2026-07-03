<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// symfony/var-exporter 8 removed LazyGhost proxies; Doctrine must switch to
// PHP 8.4 native lazy objects. Conditional because the option requires
// doctrine-bundle 2.14+, above this example's floor.
return static function (ContainerConfigurator $container): void {
    if (\trait_exists(\Symfony\Component\VarExporter\LazyGhostTrait::class)) {
        return;
    }

    $container->extension('doctrine', [
        'orm' => [
            'enable_native_lazy_objects' => true,
        ],
    ]);
};
