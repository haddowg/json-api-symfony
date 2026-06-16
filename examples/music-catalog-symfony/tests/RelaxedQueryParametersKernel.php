<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A test-only kernel variant of the example app with strict query-parameter
 * validation switched off (`json_api.strict_query_parameters: false`) — so a
 * *well-named* unrecognized top-level query-parameter family is silently ignored
 * (the pre-strict behaviour) rather than rejected with a `400`. It reuses the
 * *same* example app wiring — `config/bundles.php`, `config/packages/*`,
 * `config/services.yaml` and `config/routes/json_api.yaml` from the example project
 * dir, so every resource, serializer, hydrator and provider is discovered exactly
 * as in {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\MusicCatalogKernel} — and
 * only layers the `strict_query_parameters` override on top (the `json_api`
 * extension merges the partial override with the base `base_uri`/`servers` config).
 *
 * It is a standalone {@see Kernel} (rather than a subclass) because the example
 * kernel is `final`; it points its project dir at the example app root so the
 * config it loads is byte-identical to the shipped app, with the single
 * `strict_query_parameters` divergence the witness exists to prove (modelled on
 * {@see SchemaValidationKernel}).
 */
final class RelaxedQueryParametersKernel extends Kernel
{
    use MicroKernelTrait;

    private const string EXAMPLE_DIR = __DIR__ . '/..';

    public function getProjectDir(): string
    {
        return \realpath(self::EXAMPLE_DIR) ?: self::EXAMPLE_DIR;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-examples/music-catalog-relaxed-query-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-examples/music-catalog-relaxed-query-log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $configDir = $this->getProjectDir() . '/config';

        $container->import($configDir . '/{packages}/*.{php,yaml}');
        $container->import($configDir . '/services.yaml');

        // The single divergence from the shipped app: relax strict query-parameter
        // validation. The `json_api` extension merges this with the base config loaded
        // above (so base_uri/version/servers are unchanged).
        $container->extension('json_api', ['strict_query_parameters' => false]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import($this->getProjectDir() . '/config/routes/json_api.yaml');
    }
}
