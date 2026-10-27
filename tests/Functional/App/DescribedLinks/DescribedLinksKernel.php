<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DescribedLinks;

use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use haddowg\JsonApiBundle\Routing\OpenApiRouteLoader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * What the two `describedby` conformance kernels share: the bundle config, picked by
 * the kernel environment, and both route imports under one `/api` prefix.
 *
 * - `test`: the bundle serves the document, so links point into `/api/docs.json`;
 * - `strings`: `link_objects: false`;
 * - `published`: `document_url` names an absolute document elsewhere;
 * - `unserved`: the expose gate is closed (the kernels boot without debug), so no
 *   document route exists and no `document_url` is set;
 * - `configured`: a configured `base_uri` that carries the `/api` mount;
 * - `unmounted`: an empty `base_uri` with both imports at the root, no prefix.
 */
trait DescribedLinksKernel
{
    public const string PUBLISHED_URL = 'https://docs.example.test/openapi/{server}.json';

    public const string CONFIGURED_BASE_URI = 'https://api.example.test/api';

    private function configureJsonApi(ContainerConfigurator $container): void
    {
        $config = [
            'base_uri' => $this->environment === 'configured' ? self::CONFIGURED_BASE_URI : '',
            'version' => '1.1',
            'openapi' => ['expose_in_prod' => $this->environment !== 'unserved'],
            'atomic_operations' => ['enabled' => true],
        ];

        if ($this->environment === 'strings') {
            $config['link_objects'] = false;
        }

        if ($this->environment === 'published') {
            $config['openapi']['document_url'] = self::PUBLISHED_URL;
        }

        $container->extension('json_api', $config);
    }

    private function importRoutes(RoutingConfigurator $routes): void
    {
        $prefix = $this->environment === 'unmounted' ? '' : '/api';

        $routes->import('default', JsonApiRouteLoader::ROUTE_TYPE)->prefix($prefix);
        $routes->import('.', OpenApiRouteLoader::ROUTE_TYPE)->prefix($prefix);
    }
}
