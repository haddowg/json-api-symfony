<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\StaleContainer;

use haddowg\JsonApiBundle\DependencyInjection\InstalledVersionsCheck;
use haddowg\JsonApiBundle\JsonApiBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A kernel with no resources whose container can record versions other than the
 * installed ones, as one compiled before an upgrade would.
 */
final class StaleContainerTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, array{version: string, reference: ?string}|null>|null $recorded the versions to record, or null to record the installed ones
     */
    public function __construct(private readonly string $cacheRoot, private readonly ?array $recorded = null)
    {
        parent::__construct('test', false);
    }

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
        if (!\is_dir($this->cacheRoot . '/config')) {
            \mkdir($this->cacheRoot . '/config', 0o777, true);
        }

        return $this->cacheRoot;
    }

    public function getCacheDir(): string
    {
        return $this->cacheRoot . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->cacheRoot . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', ['test' => true, 'secret' => 'test', 'http_method_override' => false]);

        $recorded = $this->recorded;
        if ($recorded !== null) {
            $builder->addCompilerPass(new class ($recorded) implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
                /**
                 * @param array<string, array{version: string, reference: ?string}|null> $recorded
                 */
                public function __construct(private readonly array $recorded) {}

                public function process(ContainerBuilder $container): void
                {
                    $container->setParameter(InstalledVersionsCheck::PARAMETER, $this->recorded);
                }
            }, PassConfig::TYPE_BEFORE_OPTIMIZATION);
        }
    }
}
