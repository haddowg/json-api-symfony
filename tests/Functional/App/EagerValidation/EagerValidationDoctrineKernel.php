<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
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
 * The Doctrine half of the fail-loud eager-load validation pair (bundle ADR 0085):
 * the same subject `products` + related `brands` / `regions` resources as the
 * in-memory kernel, but booted in a Doctrine-configured app (DoctrineBundle + a DBAL
 * connection), so the {@see \haddowg\JsonApiBundle\Serializer\EagerLoadWarmer} is
 * proven to throw / accept identically on BOTH providers.
 *
 * The eager-load validation is pure metadata (`isToMany` + cross-type resolution), so it
 * is provider-independent — the resources need no entity mapping, and no Doctrine
 * provider is wired for them. The cache dir is keyed by the subject class so each
 * scenario compiles its own container.
 */
final class EagerValidationDoctrineKernel extends Kernel
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
        yield new DoctrineBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/eager-validation-doctrine-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/eager-validation-doctrine-cache/'
            . $this->subjectKey() . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/eager-validation-doctrine-log';
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

        $container->extension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'orm' => [
                'auto_generate_proxy_classes' => true,
                'report_fields_where_declared' => true,
                'auto_mapping' => false,
            ],
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
