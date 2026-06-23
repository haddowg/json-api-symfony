<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\AccessTokenHandler;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The in-memory custom-action conformance kernel (bundle ADR 0076): it mounts the
 * `actionWidgets` type over a writable in-memory provider/persister, registers the
 * standalone serializer/hydrator pairs backing the bespoke `renameCommands` /
 * `receipts` decoupled documents, autoconfigures every `#[AsJsonApiAction]` handler
 * in the {@see __NAMESPACE__} fixtures, and runs behind a real stateless firewall so
 * the per-action `security` gate and the serving gate evaluate (`actingAs()` resolves
 * a Bearer token to a seeded user).
 *
 * It is the witness twin of {@see \haddowg\JsonApiBundle\Tests\Functional\App\Action\Doctrine\ActionDoctrineTestKernel}:
 * the same actions, the same conformance assertions, a different data layer.
 */
final class ActionInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/action-in-memory-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/action-in-memory-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/action-in-memory-log';
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

        // Silence the 500-log noise an expected-throw test would otherwise emit.
        $builder->register('logger', \Psr\Log\NullLogger::class);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(AccessTokenHandler::class);

        // The mount type + its writable in-memory pair.
        $services->set(WidgetResource::class);
        $services->set('test.action_widgets_provider', InMemoryDataProvider::class)
            ->factory([WidgetFactory::class, 'createProvider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.action_widgets_persister', InMemoryDataPersister::class)
            ->factory([WidgetFactory::class, 'createPersister'])
            ->args([service('test.action_widgets_provider')])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);

        // Standalone serializer/hydrator pairs for the decoupled documents: the
        // bespoke `renameCommands` input command (serializer + hydrator, no persister
        // — the action supplies the blank via ActionInputFactoryInterface) and the
        // bespoke `receipts` output response (serializer only).
        $services->set(RenameCommandSerializer::class);
        $services->set(RenameCommandHydrator::class);
        $services->set(ReceiptSerializer::class);

        // The custom-action handlers — discovered by #[AsJsonApiAction] autoconfiguration.
        $services->set(PublishWidget::class);
        $services->set(ImportWidgets::class);
        $services->set(UploadArtwork::class);
        $services->set(RenameWidget::class);
        $services->set(ArchiveWidget::class);
        $services->set(RecalculateWidgets::class);
        $services->set(PinWidget::class);

        // The serving-gate witness.
        $services->set(DenyingServingSubscriber::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
