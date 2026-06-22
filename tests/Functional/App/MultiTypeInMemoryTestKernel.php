<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\MultiType\MultiTypeFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\MemberResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\PostResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\PublicMemberResource;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The in-memory half of the **multi-type-per-entity** conformance pair: one Member
 * record served as both the full {@see MemberResource} (`members`) and the curated
 * {@see PublicMemberResource} (`public-members`) — two providers over the SAME
 * objects ({@see MultiTypeFactory}) — plus a writable {@see PostResource} whose
 * `author` relation targets the curated type. Distinct cache/project dirs keep its
 * compiled container from colliding with the other kernels'.
 */
final class MultiTypeInMemoryTestKernel extends Kernel
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
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/multitype-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/multitype-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/multitype-log';
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
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(MemberResource::class);
        $services->set(PublicMemberResource::class);
        $services->set(PostResource::class);

        // Two providers over the SAME Member objects — the one record under two types.
        $services->set('test.members_provider', InMemoryDataProvider::class)
            ->factory([MultiTypeFactory::class, 'createMembers'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.public_members_provider', InMemoryDataProvider::class)
            ->factory([MultiTypeFactory::class, 'createPublicMembers'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        $services->set('test.posts_provider', InMemoryDataProvider::class)
            ->factory([MultiTypeFactory::class, 'createPosts'])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);

        // The writable `posts` persister: its resolver maps the curated
        // `public-members` linkage id back to the stored Member for relationship
        // mutation.
        $services->set('test.posts_persister', InMemoryDataPersister::class)
            ->factory([MultiTypeFactory::class, 'createPostsPersister'])
            ->tag(JsonApiBundle::DATA_PERSISTER_TAG);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', JsonApiRouteLoader::ROUTE_TYPE);
    }
}
