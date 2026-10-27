<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionLinks;

use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\AccessTokenHandler;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * What the two action-link kernels share: a token firewall with an `admin` and a `user`,
 * the bundle config picked by the kernel environment, and both route imports under
 * `/api`.
 *
 * - `test`: an empty `base_uri`, so links take the request origin plus the `/api` mount;
 * - `configured`: a configured `base_uri` that carries the mount;
 * - `missing`, `clash`, `duplicate`: a misdeclared resource ({@see LinksArticleActions})
 *   whose boot fails.
 */
trait ActionLinksKernel
{
    public const string CONFIGURED_BASE_URI = 'https://api.example.test/api';

    private function configureShared(ContainerConfigurator $container): void
    {
        $container->extension('json_api', [
            'base_uri' => $this->environment === 'configured' ? self::CONFIGURED_BASE_URI : '',
            'version' => '1.1',
            'openapi' => ['expose_in_prod' => true],
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
                    'access_token' => ['token_handler' => AccessTokenHandler::class],
                ],
            ],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('logger', \Psr\Log\NullLogger::class);
        $services->set(AccessTokenHandler::class);
        $services->set(ReviewArticle::class);
        $services->set(FeatureArticle::class);
        $services->set(ArchiveArticle::class);
    }

    private function importRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('default', JsonApiRouteLoader::ROUTE_TYPE)->prefix('/api');
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE)->prefix('/api');
    }
}
