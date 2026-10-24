<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use Composer\InstalledVersions;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Sets the options whose implicit defaults are deprecated, so a test kernel boots
 * without deprecation notices. Each keeps the behaviour the kernels had before.
 */
final class PinnedConfigDefaults
{
    public static function apply(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The option only exists from FrameworkBundle 7.3, above the lowest supported deps.
        if (\version_compare((string) InstalledVersions::getVersion('symfony/framework-bundle'), '7.3', '>=')) {
            $container->extension('framework', ['property_info' => ['with_constructor_extractor' => false]]);
        }

        if ($builder->hasExtension('zenstruck_foundry')) {
            $container->extension('zenstruck_foundry', ['persistence' => ['flush_once' => true]]);
        }
    }
}
