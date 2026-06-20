<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The in-memory half of the fail-loud eager-load validation pair (bundle ADR 0085):
 * registers ONE subject `products` resource (named via {@see $subjectResource}, set
 * before boot) plus the related `brands` / `regions` types, so the
 * {@see \haddowg\JsonApiBundle\Serializer\EagerLoadWarmer} can be invoked from the test
 * to assert it throws (or accepts) for that subject's flattened `on()` chain shape.
 *
 * No data provider is registered: the warmer validates pure metadata, so it needs only
 * the serializers. The cache dir is keyed by the subject class so each scenario compiles
 * its own container (no stale-container collision).
 */
final class EagerValidationInMemoryKernel extends Kernel
{
    use MicroKernelTrait;

    /** @var class-string<BaseEagerProductResource> */
    public static string $subjectResource = SafeProductResource::class;

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/eager-validation-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/eager-validation-cache/'
            . $this->subjectKey() . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/eager-validation-log';
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

        $services->set(static::$subjectResource);
        $services->set(EagerBrandResource::class);
        $services->set(EagerRegionResource::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }

    private function subjectKey(): string
    {
        $parts = \explode('\\', static::$subjectResource);

        return \end($parts);
    }
}
