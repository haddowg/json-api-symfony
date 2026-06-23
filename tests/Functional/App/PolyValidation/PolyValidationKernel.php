<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PolyValidation;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The polymorphic-discrimination warm-up guard fixture (guard A5): a board with a
 * polymorphic `pinned` relation over two member types, toggled by {@see $safe}
 * between a SAFE shape (both candidate resources override `getType()`) and an UNSAFE
 * shape (a candidate, `catch-all-items`, does not). Booting a cold-cache kernel runs
 * `cache:warmup` and so the non-optional
 * {@see \haddowg\JsonApiBundle\Server\ServableResourceWarmer} — the unsafe shape
 * therefore throws a `\LogicException` from `bootKernel()` (the build fails), the safe
 * shape boots clean.
 *
 * A5 is provider-agnostic (the guard reads only the relation's declared types + each
 * candidate's `getType` declaring class), so a single in-memory kernel is the witness.
 */
final class PolyValidationKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/poly-validation-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/poly-validation-cache/'
            . (static::$safe ? 'safe' : 'unsafe') . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/poly-validation-log';
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

        // The board declaring the polymorphic relation, plus its two member types. The
        // unsafe set lists a non-discriminating candidate (catch-all-items); the safe
        // set lists two discriminating candidates.
        if (static::$safe) {
            $services->set(SafePolyBoardResource::class);
            $services->set(DiscriminatingItemResource::class);
            $services->set(AlsoDiscriminatingItemResource::class);
            $types = ['poly-boards', 'discriminating-items', 'also-discriminating-items'];
        } else {
            $services->set(UnsafePolyBoardResource::class);
            $services->set(DiscriminatingItemResource::class);
            $services->set(CatchAllItemResource::class);
            $types = ['poly-boards', 'discriminating-items', 'catch-all-items'];
        }

        // The full-CRUD resources need an (empty) provider + persister per type so the
        // servability warm-up guard passes; the metadata-only fixture never serves a
        // real request.
        foreach ($types as $type) {
            $services->set('test.poly.' . $type . '_provider', InMemoryDataProvider::class)
                ->factory([PolyValidationDataFactory::class, 'provider'])
                ->args([$type])
                ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

            $services->set('test.poly.' . $type . '_persister', InMemoryDataPersister::class)
                ->factory([PolyValidationDataFactory::class, 'persister'])
                ->args([$type, service('test.poly.' . $type . '_provider')])
                ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
