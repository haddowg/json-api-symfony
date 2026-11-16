<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Builds a container from the singleton declarations a test names in {@see $services}, to
 * witness the ones the build refuses and the routes the rest get.
 */
final class SingletonBuildTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @var list<class-string>
     */
    public static array $services = [];

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/singleton-build-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        $variant = \implode('-', \array_map(static fn(string $class): string => (string) \substr((string) \strrchr($class, '\\'), 1), static::$services));

        return \sys_get_temp_dir() . '/json-api-symfony-tests/singleton-build-cache/' . ($variant !== '' ? $variant : 'none') . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/singleton-build-log';
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
        PinnedConfigDefaults::apply($container, $builder);

        $container->extension('json_api', ['base_uri' => 'https://example.test', 'version' => '1.1']);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        foreach (static::$services as $service) {
            $services->set($service);
        }

        $services->set('test.settings_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'provider']);
        $services->set('test.settings_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'persister'])
            ->args([new \Symfony\Component\DependencyInjection\Reference('test.settings_provider')]);
    }

    public static function provider(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('settings', [], static fn(object $item): string => '');
    }

    public static function persister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('settings', $provider->store(), static fn(): \stdClass => new \stdClass());
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
