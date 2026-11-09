<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedOnly;

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

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The related-only witness kernel: full-CRUD `records`, each on a related-only `imprints`
 * type, served in memory. {@see $actions} names the action handlers a test boots it with.
 */
final class RelatedOnlyTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @var list<class-string>
     */
    public static array $actions = [ReimprintRecord::class];

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/related-only-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        $variant = \implode('-', \array_map(static fn(string $class): string => (string) \substr((string) \strrchr($class, '\\'), 1), static::$actions));

        return \sys_get_temp_dir() . '/json-api-symfony-tests/related-only-cache/'
            . ($variant !== '' ? $variant : 'none') . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/related-only-log';
    }

    public static function createProvider(): InMemoryDataProvider
    {
        $records = ['r1' => new Record('r1', 'Debut', new Imprint('i1', 'Island', new Imprint('i0', 'Parent')))];

        return new InMemoryDataProvider('records', $records, static function (object $item): string {
            \assert($item instanceof Record);

            return $item->id;
        });
    }

    public static function createPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('records', $provider->store(), static fn(): Record => new Record());
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

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(RecordResource::class);
        $services->set(ImprintResource::class);
        foreach (static::$actions as $action) {
            $services->set($action);
        }

        $services->set('test.records_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'createProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.records_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'createPersister'])
            ->args([service('test.records_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
