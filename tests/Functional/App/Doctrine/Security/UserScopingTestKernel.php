<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The security kernel with `ownedNotes` limited to the signed-in user's notes by
 * {@see OwnNotesExtension}, backing the authorization guide's scoping examples.
 */
final class UserScopingTestKernel extends SecurityTestKernel
{
    public function getCacheDir(): string
    {
        return \sys_get_temp_dir() . '/json-api-symfony-tests/user-scoping-cache/' . $this->environment;
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        parent::configureContainer($container, $loader, $builder);

        $container->services()->defaults()->autowire()->autoconfigure()
            ->set(OwnNotesExtension::class);
    }
}
