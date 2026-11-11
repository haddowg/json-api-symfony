<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationFilterDefaults;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The relation filter-defaults app, booted over Doctrine or the in-memory provider.
 */
abstract class AbstractFdKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * The albums resource the next boot registers.
     *
     * @var class-string<FdAlbumResource>
     */
    public static string $albums = FdAlbumResource::class;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield from $this->dataBundles();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/' . $this->name() . '-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/' . $this->name() . '-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/' . $this->name() . '-log';
    }

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    abstract protected function dataBundles(): iterable;

    abstract protected function baseName(): string;

    /**
     * The tag attributes each resource registers with.
     *
     * @return array<string, mixed>
     */
    abstract protected function resourceTag(string $type): array;

    abstract protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void;

    private function name(): string
    {
        return $this->baseName() . (self::$albums === FdAlbumResource::class ? '' : '-' . \md5(self::$albums));
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

        $builder->register('logger', \Psr\Log\NullLogger::class);

        $services->set(self::$albums)->tag(JsonApiBundle::RESOURCE_TAG, $this->resourceTag('albums'));
        $services->set(FdTrackResource::class)->tag(JsonApiBundle::RESOURCE_TAG, $this->resourceTag('tracks'));

        $this->configureData($container, $services);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
