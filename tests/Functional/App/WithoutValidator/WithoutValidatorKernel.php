<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WithoutValidator;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use haddowg\JsonApiBundle\Validation\FilterValueValidator;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Serves `typedNotes` as an application without `symfony/validator` would: the
 * constraint bridge's services are removed before the container compiles, so every
 * optional validator argument resolves to `null`.
 */
final class WithoutValidatorKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    private static ?InMemoryDataProvider $provider = null;

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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/without-validator-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/without-validator-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/without-validator-log';
    }

    public function process(ContainerBuilder $container): void
    {
        $container->removeDefinition(ResourceValidator::class);
        $container->removeDefinition(FilterValueValidator::class);
    }

    public static function provider(): InMemoryDataProvider
    {
        return self::$provider = new InMemoryDataProvider('typedNotes', ['1' => new TypedNote('1', 'First', 1)], static fn(object $note): string => $note instanceof TypedNote ? (string) $note->id : '');
    }

    public static function persister(): InMemoryDataPersister
    {
        $provider = self::$provider ?? self::provider();

        return new InMemoryDataPersister('typedNotes', $provider->store(), static fn(): TypedNote => new TypedNote());
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass($this, PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
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
        PinnedConfigDefaults::apply($container, $builder);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(TypedNoteResource::class);
        $services->set(RecordTypedNote::class);

        $services->set('test.typed_notes_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'provider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.typed_notes_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'persister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
