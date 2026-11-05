<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntryPoint;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Http\HeaderWidgetFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Http\PlainWidgetResource;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use haddowg\JsonApiBundle\Tests\Functional\App\RouteCheck\PlainController;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\AccessTokenHandler;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A bearer-token firewall in front of a JSON:API resource and a plain route, both
 * behind `access_control`. The firewall's entry point is {@see BearerEntryPoint},
 * except in the `no_entry_point` environment, which configures none.
 */
final class EntryPointTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const string NO_ENTRY_POINT = 'no_entry_point';

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/entry-point-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/entry-point-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/entry-point-log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
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

        $firewall = [
            'pattern' => '^/',
            'stateless' => true,
            'provider' => 'in_memory',
            'access_token' => [
                'token_handler' => AccessTokenHandler::class,
            ],
        ];
        if ($this->environment !== self::NO_ENTRY_POINT) {
            $firewall['entry_point'] = BearerEntryPoint::class;
        }

        $container->extension('security', [
            'password_hashers' => [
                \Symfony\Component\Security\Core\User\InMemoryUser::class => ['algorithm' => 'plaintext'],
            ],
            'providers' => [
                'in_memory' => [
                    'memory' => [
                        'users' => [
                            'user' => ['password' => 'pass', 'roles' => ['ROLE_USER']],
                            'guest' => ['password' => 'pass', 'roles' => ['ROLE_GUEST']],
                        ],
                    ],
                ],
            ],
            'firewalls' => ['main' => $firewall],
            'access_control' => [
                ['path' => '^/', 'roles' => 'ROLE_USER'],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(AccessTokenHandler::class);
        $services->set(BearerEntryPoint::class);
        $services->set(UnauthorizedHttpExceptionListener::class)
            ->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 0]);
        $services->set(PlainController::class)->public()->tag('controller.service_arguments');
        $services->set(PlainWidgetResource::class);

        $services->set('test.entry_point_widgets_provider', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([HeaderWidgetFactory::class, 'createPlainProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.entry_point_widgets_persister', \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::class)
            ->factory([HeaderWidgetFactory::class, 'createPlainPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->add('plain', '/plain')->controller(PlainController::class);
    }
}
