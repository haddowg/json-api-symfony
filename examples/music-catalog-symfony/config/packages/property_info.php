<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// FrameworkBundle 7.3 deprecates leaving this option unset (its default flips in
// 8.0). Conditional because the option does not exist on Symfony 6.4, this
// example's floor; `false` keeps the pre-8.0 behaviour.
return static function (ContainerConfigurator $container): void {
    if (\version_compare((string) InstalledVersions::getVersion('symfony/framework-bundle'), '7.3', '<')) {
        return;
    }

    $container->extension('framework', [
        'property_info' => [
            'with_constructor_extractor' => false,
        ],
    ]);
};
