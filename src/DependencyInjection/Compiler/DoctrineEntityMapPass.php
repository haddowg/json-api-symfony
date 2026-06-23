<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DependencyInjection\Compiler;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\DataPersister\Doctrine\DoctrineDataPersister;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineServableWarmer;
use haddowg\JsonApiBundle\JsonApiBundle;
use haddowg\JsonApiBundle\Serializer\Doctrine\DoctrineRelationshipLoadState;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Builds the `type → Doctrine entity class` map for the reference Doctrine
 * adapter — the {@see DoctrineDataProvider} (reads), the
 * {@see DoctrineDataPersister} (writes) and the build-time
 * {@see DoctrineServableWarmer} (the storage-aware servability guard) — from the
 * discovered Resource services:
 * every `#[AsJsonApiResource(entity: …)]` contributes one entry, keyed by the
 * attribute's `type` override or the class's `static $type` (the same precedence
 * the runtime registry applies). Compile-time validation — a missing entity
 * class, an undeterminable type, or two resources mapping one type to different
 * entities — fails the container build rather than a request.
 *
 * A no-op when the Doctrine services are not registered (no `doctrine/orm`), so
 * the attribute stays inert in non-Doctrine applications. With an **empty** map
 * those definitions are removed instead: they could never answer for a type, and
 * an application that has `doctrine/orm` in the vendor tree but no Doctrine
 * integration wired (no `EntityManagerInterface` service) must not keep a
 * definition referencing that absent service alive. The
 * {@see DoctrineRelationshipLoadState} predicate, which also depends on the
 * `EntityManagerInterface` but carries no entity-map argument, is removed on the
 * same empty-map condition (so the {@see \haddowg\JsonApiBundle\Server\ServerFactory}'s
 * optional dependency resolves to null in a non-Doctrine-integrated app).
 */
final class DoctrineEntityMapPass implements CompilerPassInterface
{
    /**
     * The reference Doctrine adapter definitions the `type → entity` map is
     * shared across.
     */
    private const array MAPPED_DEFINITIONS = [
        DoctrineDataProvider::class,
        DoctrineDataPersister::class,
        DoctrineServableWarmer::class,
    ];

    /**
     * Doctrine-dependent definitions that take no entity map but must share the
     * provider/persister's lifecycle: removed when the map is empty, never given
     * the `$entityClassByType` argument.
     */
    private const array LIFECYCLE_ONLY_DEFINITIONS = [
        DoctrineRelationshipLoadState::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        $definitions = \array_values(\array_filter(
            self::MAPPED_DEFINITIONS,
            static fn(string $id): bool => $container->hasDefinition($id),
        ));

        if ($definitions === []) {
            return;
        }

        $lifecycleOnly = \array_values(\array_filter(
            self::LIFECYCLE_ONLY_DEFINITIONS,
            static fn(string $id): bool => $container->hasDefinition($id),
        ));

        $map = [];

        foreach ($container->findTaggedServiceIds(JsonApiBundle::RESOURCE_TAG) as $id => $tags) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass() ?? (\is_string($id) && \class_exists($id) ? $id : null);

            foreach ($tags as $tag) {
                $entity = $tag['entity'] ?? null;
                if (!\is_string($entity) || $entity === '') {
                    continue;
                }

                if (!\class_exists($entity)) {
                    throw new \LogicException(\sprintf(
                        'The entity class "%s" mapped by #[AsJsonApiResource] on service "%s" does not exist.',
                        $entity,
                        $id,
                    ));
                }

                $type = $this->typeFor($tag, $class, $id);

                if (isset($map[$type]) && $map[$type] !== $entity) {
                    throw new \LogicException(\sprintf(
                        'JSON:API type "%s" is mapped to two different Doctrine entities: "%s" and "%s".',
                        $type,
                        $map[$type],
                        $entity,
                    ));
                }

                $map[$type] = $entity;
            }
        }

        if ($map === []) {
            foreach ([...$definitions, ...$lifecycleOnly] as $id) {
                $container->removeDefinition($id);
            }

            return;
        }

        foreach ($definitions as $id) {
            $container->getDefinition($id)->setArgument('$entityClassByType', $map);
        }
    }

    /**
     * The resource type a tag's entity mapping is keyed by: the tag's `type`
     * attribute (recorded from the #[AsJsonApiResource] override) or the
     * Resource class's `static $type`.
     *
     * @param array<string, mixed> $tag
     */
    private function typeFor(array $tag, ?string $class, string $id): string
    {
        $type = $tag['type'] ?? null;
        if (\is_string($type) && $type !== '') {
            return $type;
        }

        if ($class !== null && \is_subclass_of($class, AbstractResource::class) && $class::$type !== '') {
            return $class::$type;
        }

        throw new \LogicException(\sprintf(
            'Cannot determine the JSON:API type for the entity mapping on service "%s": '
            . 'declare a non-empty static $type on the Resource class or pass `type:` to #[AsJsonApiResource].',
            $id,
        ));
    }
}
