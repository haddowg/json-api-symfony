<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\GateOrder;

use haddowg\JsonApiBundle\Tests\Functional\App\PinnedConfigDefaults;
use haddowg\JsonApiBundle\Tests\Functional\App\Security\AccessTokenHandler;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The configuration both gate-order kernels share: a stateless Bearer firewall over four
 * users, Atomic Operations on, the recording listener and the admin-only action.
 */
final class GateKernelConfig
{
    public static function apply(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);
        PinnedConfigDefaults::apply($container, $builder);

        $container->extension('json_api', [
            'base_uri' => 'https://example.test',
            'version' => '1.1',
            'atomic_operations' => ['enabled' => true],
        ]);

        $container->extension('security', [
            'password_hashers' => [InMemoryUser::class => ['algorithm' => 'plaintext']],
            'providers' => [
                'in_memory' => [
                    'memory' => [
                        'users' => [
                            'admin' => ['password' => 'pass', 'roles' => ['ROLE_ADMIN', 'ROLE_USER']],
                            'user' => ['password' => 'pass', 'roles' => ['ROLE_USER']],
                            'other' => ['password' => 'pass', 'roles' => ['ROLE_USER']],
                            'reader' => ['password' => 'pass', 'roles' => ['ROLE_USER', 'ROLE_NO_LIST']],
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
    }

    public static function services(DefaultsConfigurator $services): void
    {
        $services->set(AccessTokenHandler::class);
        $services->set(GateListener::class);
        $services->set(PokeGateWidget::class);
    }
}
