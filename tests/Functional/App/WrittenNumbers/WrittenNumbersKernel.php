<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WrittenNumbers;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Serves `numberCards` over an in-memory store, with atomic operations and an action
 * taking one as its input.
 */
final class WrittenNumbersKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/written-numbers-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/written-numbers-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/written-numbers-log';
    }

    public static function provider(): InMemoryDataProvider
    {
        $card = new NumberCard();
        $card->id = '1';

        return self::$provider = new InMemoryDataProvider('numberCards', ['1' => $card], static fn(object $card): string => $card instanceof NumberCard ? (string) $card->id : '');
    }

    public static function persister(): InMemoryDataPersister
    {
        $provider = self::$provider ?? self::provider();

        return new InMemoryDataPersister('numberCards', $provider->store(), static fn(): NumberCard => new NumberCard());
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
            'atomic_operations' => ['enabled' => true],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(NumberCardResource::class);
        $services->set(ScoreNumberCard::class);

        $services->set('test.number_cards_provider', InMemoryDataProvider::class)
            ->factory([self::class, 'provider'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
        $services->set('test.number_cards_persister', InMemoryDataPersister::class)
            ->factory([self::class, 'persister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
