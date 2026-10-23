<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

/**
 * The soft-hidden rows are hidden by a Doctrine query extension.
 */
final class IncludeScopingDoctrineTestKernel extends AbstractIncludeScopingDoctrineKernel
{
    protected function name(): string
    {
        return 'extension';
    }

    protected function scope(DefaultsConfigurator $services): void
    {
        $services->set(HiddenRowsExtension::class);
    }
}
