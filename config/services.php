<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Bundle service definitions.
 *
 * Scaffold stage: empty. Phase 0 populates this with the Server factory, the
 * PSR-7 bridge wiring, the Target resolver, and the kernel.request / kernel.view /
 * kernel.exception listeners. Services should be defined private, autowired, and
 * autoconfigured.
 */
return static function (ContainerConfigurator $container): void {
    $container->services();
};
