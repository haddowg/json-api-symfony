<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Scope\GrantedScopesProviderInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;
use haddowg\JsonApi\Scope\ScopeCatalogInterface;
use haddowg\JsonApi\Scope\ScopeMatcherInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Finds the app's OAuth scope services by the core interface each implements, so an app
 * only has to write the class. An explicit alias or service for the interface wins; with
 * none, the one autoconfigured implementation is aliased, and two or more fail the build
 * asking for an alias.
 */
final class ScopeServicesPass implements CompilerPassInterface
{
    public const string TAG_PREFIX = 'haddowg.json_api.scope.';

    /** @var array<class-string, string> interface → tag suffix */
    public const array INTERFACES = [
        GrantedScopesProviderInterface::class => 'granted',
        OperationScopeResolverInterface::class => 'resolver',
        ScopeMatcherInterface::class => 'matcher',
        ScopeCatalogInterface::class => 'catalog',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::INTERFACES as $interface => $suffix) {
            if ($container->has($interface)) {
                continue;
            }

            $ids = \array_keys($container->findTaggedServiceIds(self::TAG_PREFIX . $suffix));
            if (\count($ids) > 1) {
                throw new \LogicException(\sprintf(
                    'Several services implement %s: %s. Alias the interface to the one the JSON:API bundle should use.',
                    $interface,
                    \implode(', ', $ids),
                ));
            }

            if ($ids !== []) {
                $container->setAlias($interface, $ids[0]);
            }
        }
    }
}
