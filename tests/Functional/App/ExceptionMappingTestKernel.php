<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\BothMappedException;
use haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\ConfigMappedException;
use haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\NativeJsonApiException;
use haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\TestExceptionMapper;
use haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\ThrowingWidget;
use haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\ThrowingWidgetResource;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The exception-mapping harness kernel (gap G15 / bundle ADR 0073). It serves a
 * `throwingWidgets` type whose read hook throws a chosen test exception on a
 * JSON:API route (`GET /throwingWidgets?throwSignal=config|mapper|both|unmapped|jsonapi`),
 * and wires **both** facets of the mapping seam:
 *
 *  - the `json_api.exceptions` config map points {@see ConfigMappedException} at a
 *    `402` status (the config-driven ConfiguredExceptionMapper facet);
 *  - the tagged {@see TestExceptionMapper} maps a `MapperMappedException` to a rich
 *    error (the ExceptionMapperInterface facet) — auto-tagged by autoconfiguration.
 *
 * To exercise priority/ordering, {@see BothMappedException} is named in **both** the
 * config map (`409`) and the tagged mapper (a rich `423`): the higher-priority tagged
 * mapper must win. To exercise the invariant, {@see NativeJsonApiException} is also
 * named in the config map (`599`) and claimed by the tagged mapper — yet it must
 * still render natively, never via either. An {@see \haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping\UnmappedException}
 * is named in neither, so it falls through to the generic `500`.
 *
 * `strict_query_parameters` is off so the `?throwSignal=` signal param reaches the read
 * hook rather than 400-ing as an unrecognized family.
 */
final class ExceptionMappingTestKernel extends Kernel
{
    use MicroKernelTrait;

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/exception-mapping-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/exception-mapping-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/exception-mapping-log';
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
            // The `?throwSignal=` family is camelCased (clearing the spec's always-on
            // custom-parameter naming baseline) but is not a recognized family, so relax
            // strict validation to let it through to the read hook rather than 400.
            'strict_query_parameters' => false,
            // Facet 1: map the plain domain exception to a 402 status.
            'exceptions' => [
                ConfigMappedException::class => 402,
                // Also matched by the tagged TestExceptionMapper (which maps it to a
                // rich 423); the ordering test asserts the higher-priority tagged
                // mapper wins over this lower-priority config entry.
                BothMappedException::class => 409,
                // Deliberately also maps the core JSON:API exception (to a sentinel
                // 599). The invariant test asserts the native rendering still wins —
                // the listener never consults the config map (or any mapper) for a
                // JsonApiExceptionInterface, even when both would match it.
                NativeJsonApiException::class => 599,
            ],
        ]);

        // The generic-500 arm logs the unexpected throwable through the `logger`
        // service (both our ExceptionListener and the framework's ErrorListener), which
        // by default writes to stderr/stdout via error_log. Pre-register a NullLogger
        // directly on the builder — before the services() block below — so Symfony's
        // LoggerPass (which only registers its default when no `logger` exists) stands
        // down and the deliberate "unmapped" throwable does not leak into the test
        // output. Rendering, not logging, is under test here.
        $builder->register('logger', NullLogger::class);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(ThrowingWidgetResource::class);

        $services->set('test.throwing_widgets_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'createThrowingWidgetsProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.throwing_widgets_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'createThrowingWidgetsPersister'])
            ->args([service('test.throwing_widgets_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        // Facet 2: the tagged ExceptionMapperInterface. autoconfigure() lets the
        // bundle's registerForAutoconfiguration tag it as a json_api.exception_mapper.
        $services->set(TestExceptionMapper::class);
    }

    public static function createThrowingWidgetsProvider(): InMemoryDataProvider
    {
        $widgets = ['tw1' => new ThrowingWidget('tw1', 'Sprocket')];

        return new InMemoryDataProvider('throwingWidgets', $widgets, static function (object $item): string {
            \assert($item instanceof ThrowingWidget);

            return $item->id;
        });
    }

    public static function createThrowingWidgetsPersister(InMemoryDataProvider $provider): InMemoryDataPersister
    {
        return new InMemoryDataPersister('throwingWidgets', $provider->store(), static fn(): ThrowingWidget => new ThrowingWidget());
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
