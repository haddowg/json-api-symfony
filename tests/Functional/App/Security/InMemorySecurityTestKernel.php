<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Security;

use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The in-memory declarative-authorization witness kernel (bundle ADR 0043): the same
 * `securedWidgets` role gates as the Doctrine
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecurityTestKernel},
 * but over the in-memory provider/persister — proving authorization is
 * provider-agnostic and nothing in the seam couples to Doctrine.
 */
final class InMemorySecurityTestKernel extends Kernel
{
    use MicroKernelTrait;

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/in-memory-security-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/in-memory-security-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/in-memory-security-log';
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

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        $container->extension('security', [
            'password_hashers' => [
                \Symfony\Component\Security\Core\User\InMemoryUser::class => ['algorithm' => 'plaintext'],
            ],
            'providers' => [
                'in_memory' => [
                    'memory' => [
                        'users' => [
                            // An admin is also a user (the realistic role model), so a
                            // `ROLE_USER` collection read (a `security` default now
                            // cascades to the collection) is reachable by an admin too.
                            'admin' => ['password' => 'pass', 'roles' => ['ROLE_ADMIN', 'ROLE_USER']],
                            'user' => ['password' => 'pass', 'roles' => ['ROLE_USER']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'stateless' => true,
                    'provider' => 'in_memory',
                    'access_token' => [
                        'token_handler' => AccessTokenHandler::class,
                    ],
                ],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(AccessTokenHandler::class);
        $services->set(InMemorySecuredWidgetResource::class);

        $services->set('test.in_memory_secured_widgets_provider', \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider::class)
            ->factory([InMemorySecuredWidgetFactory::class, 'createProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.in_memory_secured_widgets_persister', \haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister::class)
            ->factory([InMemorySecuredWidgetFactory::class, 'createPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
