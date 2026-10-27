<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeCatalogInterface;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * What the two scope kernels share: an `oauth2` scope scheme, an Atomic Operations
 * endpoint needing `batch`, and the app's scope services, picked by environment.
 *
 * - `test`: every scope service, enforcement on;
 * - `unenforced`: no grant provider, so nothing is enforced but the document still lists
 *   the scopes;
 * - `undeclared`: the catalogue leaves `comments:read` out;
 * - `bearer_scheme`: the scopes are listed under the `bearer` scheme;
 * - `public_scoped` (in-memory only): a public author read is given a scope;
 * - `unknown_relation` (in-memory only): a comment declares scopes for a relation it
 *   does not have.
 */
trait ScopesKernel
{
    private function configureScopes(ContainerConfigurator $container): DefaultsConfigurator
    {
        $container->extension('json_api', [
            'base_uri' => '',
            'version' => '1.1',
            'openapi' => [
                'expose_in_prod' => true,
                // The test resources declare no field examples, so none are generated.
                'examples' => false,
                'security' => [
                    'schemes' => [
                        'bearer' => ['type' => 'bearer'],
                        'oauth2' => [
                            'type' => 'oauth2',
                            'flows' => ['clientCredentials' => ['tokenUrl' => 'https://example.test/oauth/token']],
                        ],
                    ],
                    'default_requirement' => ['bearer'],
                    'scope_scheme' => $this->environment === 'bearer_scheme' ? 'bearer' : 'oauth2',
                ],
            ],
            'atomic_operations' => ['enabled' => true, 'scopes' => ['batch']],
        ]);

        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set('logger', RecordingLogger::class)->public();
        $services->set(ArticleScopes::class);
        $services->alias(OperationScopeResolverInterface::class, ArticleScopes::class);
        $services->set(ArticleScopeCatalog::class)->arg('$complete', $this->environment !== 'undeclared');
        $services->alias(ScopeCatalogInterface::class, ArticleScopeCatalog::class);
        if ($this->environment !== 'unenforced') {
            $services->set(HeaderGrantedScopes::class);
            $services->alias(GrantedScopesProviderInterface::class, HeaderGrantedScopes::class);
        }
        $services->set(PublishArticle::class);

        return $services;
    }

    private function importRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('default', JsonApiRouteLoader::ROUTE_TYPE);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE);
    }
}
