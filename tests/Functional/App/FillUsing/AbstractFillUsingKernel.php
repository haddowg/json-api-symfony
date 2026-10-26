<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

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
 * Serves `fill-files`, whose relations are written by fillUsing() hooks, over
 * `fill-agencies` and `fill-members`, each with a hidden row.
 */
abstract class AbstractFillUsingKernel extends Kernel
{
    use MicroKernelTrait;

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

    abstract protected function name(): string;

    /**
     * @return list<class-string>
     */
    protected function resources(): array
    {
        return [FillAgencyResource::class, FillMemberResource::class, FillFileResource::class];
    }

    abstract protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void;

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
            'atomic_operations' => ['enabled' => true],
        ]);

        // The refusal witness logs the 403 it raises; keep it out of the test output.
        $builder->register('logger', \Psr\Log\NullLogger::class);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        foreach ($this->resources() as $resource) {
            $services->set($resource);
        }
        $services->set(RefuseAgencyThreeSubscriber::class);

        $this->configureData($container, $services);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
