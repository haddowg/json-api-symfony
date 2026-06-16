<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
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
 * The declarative-authorization witness kernel (bundle ADR 0043): the Doctrine
 * provider/persister behind a real Symfony firewall (in-memory user provider +
 * HTTP-Basic authenticator + a password hasher) so the
 * {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber} evaluates each
 * type's `#[AsJsonApiResource(security: …)]` expression against a real token.
 *
 * Three types prove the surface: `securedWidgets` (role gates, with per-operation
 * `ROLE_ADMIN` overrides on create/delete over a `ROLE_USER` default), `ownedWidgets`
 * (an `is_granted('EDIT', object)` ownership gate backed by {@see OwnedWidgetVoter}),
 * and `openWidgets` (no security — ungated). The schema is created and seeded by the
 * test.
 */
final class SecurityTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/security-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/security-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/security-log';
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

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'JsonApiSecurityTestApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security',
                    'is_bundle' => false,
                ],
            ],
        ];

        if (!\trait_exists(\Symfony\Component\VarExporter\LazyGhostTrait::class)) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'orm' => $orm,
        ]);

        // An in-memory user provider over plaintext passwords + an HTTP-Basic
        // firewall. Stateless: the test authenticates per request via the
        // Authorization header (Request::create's PHP_AUTH_USER/PW). The firewall is
        // optional (no access_control forces auth), so an unauthenticated request
        // still reaches the controller and the security subscriber gates it.
        $container->extension('security', [
            'password_hashers' => [
                \Symfony\Component\Security\Core\User\InMemoryUser::class => ['algorithm' => 'plaintext'],
            ],
            'providers' => [
                'in_memory' => [
                    'memory' => [
                        'users' => [
                            'admin' => ['password' => 'pass', 'roles' => ['ROLE_ADMIN']],
                            'user' => ['password' => 'pass', 'roles' => ['ROLE_USER']],
                            'ada' => ['password' => 'pass', 'roles' => ['ROLE_USER']],
                            'grace' => ['password' => 'pass', 'roles' => ['ROLE_USER']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'stateless' => true,
                    'provider' => 'in_memory',
                    'http_basic' => true,
                ],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(SecuredWidgetResource::class);
        $services->set(OwnedWidgetResource::class);
        $services->set(OpenWidgetResource::class);
        $services->set(OwnedWidgetVoter::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
