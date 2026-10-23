<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The soft-hidden rows are hidden by an application provider for the agency and
 * member types, wrapping the Doctrine provider; the advertisers stay on Doctrine,
 * which has no extension.
 */
final class IncludeScopingProviderTestKernel extends AbstractIncludeScopingDoctrineKernel
{
    protected function name(): string
    {
        return 'provider';
    }

    /**
     * Without the pivot relations: the application provider serves no pivot reads.
     */
    protected function advertiserResources(): array
    {
        return [ProviderDefaultScopingAdvertiserResource::class, ProviderPartnerScopingAdvertiserResource::class];
    }

    protected function scope(DefaultsConfigurator $services): void
    {
        $services->set('test.hiding_provider', HidingProvider::class)
            ->autoconfigure(false)
            ->args([
                ['agencies', 'members', 'partner-agencies', 'partner-members'],
                service(DoctrineDataProvider::class),
            ])
            ->tag(JsonApiBundle::DATA_PROVIDER_TAG);
    }
}
