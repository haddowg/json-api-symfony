<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApiBundle\Validation\FilterValueValidator;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

/**
 * {@see IncludeScopingDoctrineTestKernel} as it runs without symfony/validator: the
 * validator bridge's services are removed, so the handler's validator is null.
 */
final class IncludeScopingNoValidatorDoctrineTestKernel extends AbstractIncludeScopingDoctrineKernel
{
    protected function name(): string
    {
        return 'no-validator';
    }

    protected function scope(DefaultsConfigurator $services): void
    {
        $services->set(HiddenRowsExtension::class);
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->removeDefinition(ResourceValidator::class);
                $container->removeDefinition(FilterValueValidator::class);
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
    }
}
