<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedTargetValidation;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PolyValidation\PolyValidationDataFactory;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The related-endpoint-target warm-up guard fixture: one `shelves` type whose
 * `crates` relation points at a type the server does not register, toggled by
 * {@see $safe} between exposing its related endpoint (unsafe) and being linkage-only
 * (safe). Booting a cold-cache kernel runs `cache:warmup` and so the non-optional
 * {@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer} — the unsafe shape
 * throws {@see \haddowg\JsonApi\OpenApi\RelatedTypeNotRegistered} from
 * `bootKernel()`, the safe shape boots clean.
 *
 * The guard reads core's {@see \haddowg\JsonApi\OpenApi\ProjectedTypes::relatedOnly()}
 * over the projected metadata, so one in-memory kernel is the witness.
 */
final class RelatedTargetValidationKernel extends Kernel
{
    use MicroKernelTrait;

    public static bool $safe = true;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/related-target-validation-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/related-target-validation-cache/'
            . (static::$safe ? 'safe' : 'unsafe') . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/related-target-validation-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(static::$safe ? SafeShelfResource::class : UnsafeShelfResource::class);

        // The full-CRUD resource needs an (empty) provider + persister so the
        // servability guard passes; this fixture never serves a real request.
        $services->set('test.related_target.shelves_provider', InMemoryDataProvider::class)
            ->factory([PolyValidationDataFactory::class, 'provider'])
            ->args(['shelves'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.related_target.shelves_persister', InMemoryDataPersister::class)
            ->factory([PolyValidationDataFactory::class, 'persister'])
            ->args(['shelves', service('test.related_target.shelves_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
