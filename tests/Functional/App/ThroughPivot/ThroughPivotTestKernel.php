<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Logging\Middleware as DbalLoggingMiddleware;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\AccessTokenHandler;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Serves tracks on playlists through pivot rows from SQLite, with hidden users kept out
 * by a query extension and auditors listable by admins only. With {@see $broken} it also
 * registers {@see TpBrokenResource}, whose declaration the warm-up refuses.
 */
final class ThroughPivotTestKernel extends Kernel
{
    use MicroKernelTrait;

    public static ?string $broken = null;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/through-pivot-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/through-pivot-cache/' . (self::$broken ?? 'ok') . '-' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/through-pivot-log';
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

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'JsonApiThroughPivotTestApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot',
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
        $container->extension('security', [
            'password_hashers' => [
                \Symfony\Component\Security\Core\User\InMemoryUser::class => ['algorithm' => 'plaintext'],
            ],
            'providers' => [
                'in_memory' => [
                    'memory' => [
                        'users' => [
                            'admin' => ['password' => 'pass', 'roles' => ['ROLE_ADMIN', 'ROLE_USER']],
                            'ada' => ['password' => 'pass', 'roles' => ['ROLE_USER']],
                        ],
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'stateless' => true,
                    'provider' => 'in_memory',
                    'access_token' => ['token_handler' => AccessTokenHandler::class],
                ],
            ],
        ]);
        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
            'openapi' => ['expose_in_prod' => true],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(AccessTokenHandler::class);
        $services->set('logger', TpErrorLogger::class)->public();
        $services->set(QueryCountingLogger::class)->public();
        $services->set(DbalLoggingMiddleware::class)
            ->args([new Reference(QueryCountingLogger::class)])
            ->tag('doctrine.middleware');
        foreach ([HiddenTpUserExtension::class, TpUserResource::class, TpAuditorResource::class, TpPlaylistResource::class, TpTrackResource::class, TpAlbumResource::class, TpFamilyTrackResource::class] as $class) {
            $services->set($class);
        }
        if (self::$broken === 'association-class-without-stored-fields') {
            $services->set(TpBrokenTrackResource::class);
        } elseif (self::$broken !== null && \str_starts_with(self::$broken, 'legacy-')) {
            TpLegacyPivotTrackResource::$declaration = self::$broken;
            $services->set(TpLegacyPivotTrackResource::class);
            if (self::$broken === 'legacy-through-several') {
                $services->set(TpNoteResource::class);
                $services->set(TpNoteAliasResource::class);
            }
        } elseif (self::$broken !== null) {
            TpBrokenResource::$declaration = self::$broken;
            $services->set(TpBrokenResource::class);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
