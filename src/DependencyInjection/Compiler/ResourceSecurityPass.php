<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects the declarative authorization expressions a resource declares via
 * `#[AsJsonApiResource(security: …, securityCreate: …, …)]` (bundle ADR 0043) — the
 * extension records each of the five strings as a tag attribute on the
 * {@see JsonApiBundle::RESOURCE_TAG} — and assembles a type-keyed
 * `type → {default, create, update, delete, read}` scalar map, injected into the
 * {@see ResourceSecurityRegistry} the {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber}
 * queries.
 *
 * The map flows as plain scalars (the container cannot dump value objects as a
 * service argument); the registry rebuilds the {@see \haddowg\JsonApiBundle\Security\ResourceSecurity}
 * objects from it. Security is type-keyed and server-independent, so the `server`
 * tag attribute is irrelevant here. The pass only runs when the registry is defined
 * — it is registered only when `symfony/security-core` is installed — so without the
 * optional dependency the whole authorization layer is absent and a declared
 * `security` is inert (the strings are simply never read).
 */
final class ResourceSecurityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ResourceSecurityRegistry::class)) {
            return;
        }

        /** @var array<string, array{default?: string, create?: string, update?: string, delete?: string, read?: string}> $expressions */
        $expressions = [];

        foreach ($container->findTaggedServiceIds(JsonApiBundle::RESOURCE_TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);
            if ($class === null) {
                continue;
            }

            // A resource bears two RESOURCE_TAG tags (AbstractResource
            // autoconfiguration + the attribute callback), so the type and each
            // expression are resolved across all tags — the empty autoconfig tag must
            // not erase the attribute's values.
            $type = $this->typeFromTags($tags, $class);
            if ($type === '') {
                continue;
            }

            foreach (['default', 'create', 'update', 'delete', 'read'] as $key) {
                $value = $this->securityFromTags($tags, $key);
                if ($value !== null) {
                    $expressions[$type][$key] = $value;
                }
            }
        }

        $container->getDefinition(ResourceSecurityRegistry::class)->setArgument('$expressions', $expressions);
    }

    /**
     * The JSON:API type for a resource across its tags: the first tag's `type`
     * override, else the `AbstractResource`'s static `$type`.
     *
     * @param array<array<string, mixed>> $tags
     */
    private function typeFromTags(array $tags, string $class): string
    {
        foreach ($tags as $tag) {
            $type = $tag['type'] ?? null;
            if (\is_string($type) && $type !== '') {
                return $type;
            }
        }

        return \is_a($class, AbstractResource::class, true) ? $class::$type : '';
    }

    /**
     * The security expression recorded under the `security_<key>` tag attribute
     * across a resource's tags, or `null` when no tag carries it.
     *
     * @param array<array<string, mixed>> $tags
     */
    private function securityFromTags(array $tags, string $key): ?string
    {
        $attribute = 'security_' . $key;
        foreach ($tags as $tag) {
            $value = $tag[$attribute] ?? null;
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
