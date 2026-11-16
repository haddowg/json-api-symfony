<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\AccessTokenHandler;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use haddowg\JsonApiBundle\Tests\Functional\App\Scopes\HeaderGrantedScopes;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The singleton witness kernel on Doctrine: the `inboxes` singleton at `/inbox`, scoped
 * to its owner by {@see OwnInboxExtension}, its `inbox-items`, and the composed
 * `preferences` singleton, behind a Bearer firewall whose token is the user. `/inbox` and
 * the paths under it need a signed-in user. A request carrying `X-Granted-Scopes` is
 * checked against {@see InboxScopes}.
 */
class SingletonDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        if ($this->doctrine()) {
            yield new DoctrineBundle();
        }
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

    protected function doctrine(): bool
    {
        return true;
    }

    protected function name(): string
    {
        return 'singleton-doctrine';
    }

    public static function preferences(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('preferences', [
            'p-ada' => new Preferences('p-ada', 'ada', 'dark'),
            'p-grace' => new Preferences('p-grace', 'grace', 'light'),
        ]);
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

        $users = [];
        foreach (['ada', 'grace', 'hopper', 'ghost', 'mallory'] as $user) {
            $users[$user] = ['password' => 'pass', 'roles' => ['ROLE_USER']];
        }
        $container->extension('security', [
            'password_hashers' => [
                \Symfony\Component\Security\Core\User\InMemoryUser::class => ['algorithm' => 'plaintext'],
            ],
            'providers' => ['in_memory' => ['memory' => ['users' => $users]]],
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'stateless' => true,
                    'provider' => 'in_memory',
                    'access_token' => ['token_handler' => AccessTokenHandler::class],
                ],
            ],
            'access_control' => [
                ['path' => '^/inbox(/|$)', 'roles' => 'ROLE_USER'],
            ],
        ]);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
            'atomic_operations' => ['enabled' => true],
            'openapi' => [
                'expose_in_prod' => true,
                'examples' => false,
                'security' => [
                    'schemes' => ['bearer' => ['type' => 'bearer']],
                    'scope_scheme' => 'bearer',
                ],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('logger', \Psr\Log\NullLogger::class);
        $services->set(AccessTokenHandler::class);
        $services->set(MarkInboxRead::class);
        $services->set(InboxScopes::class);
        $services->alias(OperationScopeResolverInterface::class, InboxScopes::class);
        $services->set(HeaderGrantedScopes::class);
        $services->alias(GrantedScopesProviderInterface::class, HeaderGrantedScopes::class);
        $services->set(PreferencesSerializer::class);
        $services->set('test.preferences_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'preferences'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $this->configureData($container, $services);
    }

    protected function configureData(ContainerConfigurator $container, DefaultsConfigurator $services): void
    {
        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'Singleton' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => __NAMESPACE__,
                    'is_bundle' => false,
                ],
            ],
        ];
        if (!\trait_exists(\Symfony\Component\VarExporter\LazyGhostTrait::class)) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm' => $orm,
        ]);

        $services->set(DoctrineInboxResource::class);
        $services->set(DoctrineInboxItemResource::class);
        $services->set(OwnInboxExtension::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
