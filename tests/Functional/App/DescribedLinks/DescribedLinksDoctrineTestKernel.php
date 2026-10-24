<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DescribedLinks;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineArticleResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineAuthorResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineCommentResource;
use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

/**
 * The Doctrine `describedby` conformance kernel: the articles/authors/comments
 * surface over in-memory SQLite and an `asLink` action, under `/api`, configured per
 * environment by {@see DescribedLinksKernel}.
 */
final class DescribedLinksDoctrineTestKernel extends Kernel
{
    use DescribedLinksKernel;
    use MicroKernelTrait;

    /**
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new ZenstruckFoundryBundle();
        yield new JsonApiBundle();
    }

    public function getProjectDir(): string
    {
        // Point the project dir at the temp tree so FrameworkBundle's
        // auto-generated config lands there, not in the bundle.
        $dir = \sys_get_temp_dir() . '/json-api-symfony-tests/described-links-doctrine-app';
        if (!\is_dir($dir . '/config')) {
            \mkdir($dir . '/config', 0o777, true);
        }

        return $dir;
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/described-links-doctrine-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/described-links-doctrine-log';
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

        $orm = [
            'auto_generate_proxy_classes' => true,
            'report_fields_where_declared' => true,
            'auto_mapping' => false,
            'mappings' => [
                'JsonApiTestApp' => [
                    'type' => 'attribute',
                    'dir' => \dirname(__DIR__) . '/Doctrine',
                    'prefix' => 'haddowg\JsonApiBundle\Tests\Functional\App\Doctrine',
                    'is_bundle' => false,
                ],
            ],
        ];

        // Symfony 8 removed var-exporter's LazyGhostTrait, and Doctrine ORM
        // then requires PHP >= 8.4 native lazy objects for its proxies. Set
        // conditionally: the option only exists on DoctrineBundle versions new
        // enough to support that stack, while the lowest supported deps
        // (Symfony 6.4) still ship LazyGhost and predate the option.
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

        $this->configureJsonApi($container);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('logger', \Psr\Log\NullLogger::class);

        $services->set(DoctrineArticleResource::class);
        $services->set(PublishArticle::class);
        $services->set(DoctrineAuthorResource::class);
        $services->set(DoctrineCommentResource::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $this->importRoutes($routes);
    }
}
