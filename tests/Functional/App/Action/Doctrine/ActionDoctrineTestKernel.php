<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action\Doctrine;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\ArchiveWidget;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\DenyingServingSubscriber;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\ImportWidgets;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\PinWidget;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\PublishWidget;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\RecalculateWidgets;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\ReceiptSerializer;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\RenameCommandHydrator;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\RenameCommandSerializer;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\RenameWidget;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\UploadArtwork;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\AccessTokenHandler;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

/**
 * The Doctrine custom-action conformance kernel (bundle ADR 0076): the witness twin of
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Action\ActionInMemoryTestKernel},
 * running the SAME actions and the SAME conformance assertions over the reference
 * Doctrine provider/persister against an in-memory SQLite database. It maps the
 * `Action\Doctrine` namespace (the {@see WidgetEntity}) and runs behind a real
 * stateless firewall so the per-action `security` and serving gates evaluate.
 *
 * The schema is created and seeded by the test's `afterBoot()` (the in-memory database
 * lives and dies with the kernel's connection).
 */
final class ActionDoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new ZenstruckFoundryBundle();
        yield new SecurityBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/action-doctrine-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/action-doctrine-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/action-doctrine-log';
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
                'JsonApiActionApp' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\Action\Doctrine',
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
                            'admin' => ['password' => 'pass', 'roles' => ['ROLE_ADMIN']],
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

        $builder->register('logger', \Psr\Log\NullLogger::class);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(AccessTokenHandler::class);

        // The mount type, mapped to its entity (served by the Doctrine fallbacks).
        $services->set(WidgetResource::class);

        // The decoupled-document standalone serializer/hydrator pairs.
        $services->set(RenameCommandSerializer::class);
        $services->set(RenameCommandHydrator::class);
        $services->set(ReceiptSerializer::class);

        // The custom-action handlers (the same fixtures the in-memory kernel uses).
        $services->set(PublishWidget::class);
        $services->set(ImportWidgets::class);
        $services->set(UploadArtwork::class);
        $services->set(RenameWidget::class);
        $services->set(ArchiveWidget::class);
        $services->set(RecalculateWidgets::class);
        $services->set(PinWidget::class);

        $services->set(DenyingServingSubscriber::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
