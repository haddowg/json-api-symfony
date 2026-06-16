<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The example application kernel — a real Symfony app kernel (loading
 * `config/bundles.php`, `config/packages/*`, and `config/routes/*` from the
 * project dir) rather than a bundle MicroKernel test kernel that wires services
 * imperatively. It is the model an integrating app copies: register the three
 * bundles in `bundles.php`, configure `json_api`/`doctrine` in `config/packages`,
 * import the route loader in `config/routes`, and let autoconfiguration discover
 * the resources/serializers/hydrators/providers in `src/`.
 *
 * The {@see MicroKernelTrait} default `configureContainer()` loads
 * `config/{packages}/*` and registers every service in `src/` (autowired +
 * autoconfigured) via the `services.yaml`/attribute conventions; here the resource
 * services are autoconfigured purely from extending `AbstractResource`, so the
 * example needs no hand-written service definitions.
 */
final class MusicCatalogKernel extends Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-examples/music-catalog-cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-examples/music-catalog-log';
    }
}
