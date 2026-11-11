<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch;

use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

/**
 * The configuration both kernels share, and the providers of the related types.
 */
final class RelatedProviderBatchKernelConfig
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
        ]);
    }

    public static function relatedTypes(DefaultsConfigurator $services): void
    {
        $services->set(RunProgressResource::class);
        $services->set(RunAdvertiserResource::class);
        $services->set(RunNoteResource::class);

        $services->set(RunReadsProvider::class);
        $services->set(RunNotesProvider::class);
    }

    public static function directory(string $name): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/' . $name;
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }
}
