<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer\Doctrine;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;

/**
 * The reference Doctrine load-state predicate: answers, **without triggering a
 * load**, whether a relation's linkage is already in memory for a managed
 * entity, so a relation that opted into
 * {@see RelationInterface::linkageOnlyWhenLoaded()} can omit its `data` member
 * rather than force a lazy round-trip just to render identifiers.
 *
 * The decision is by cardinality:
 *
 *  - **To-many** ({@see RelationInterface::isToMany()}): loaded only when the
 *    backing association is an already-initialised collection. The association
 *    property is read off the entity and, when it is a Doctrine
 *    {@see \Doctrine\ORM\PersistentCollection} (an {@see AbstractLazyCollection}),
 *    its `isInitialized()` is consulted directly — which neither iterates nor
 *    initialises it. A plain array/`ArrayCollection` (a freshly-instantiated,
 *    not-yet-persisted entity, or an eager fetch) counts as loaded.
 *
 *  - **To-one**: always loaded. A lazy `ManyToOne` proxy already carries its
 *    identifier, so emitting the resource-identifier linkage reads the foreign
 *    key off the proxy and never triggers a database load.
 *
 * The JSON:API relationship maps to its storage association by the relation
 * field's {@see \haddowg\JsonApi\Resource\Field\FieldInterface::column()} (the
 * backing property name); a relation whose column does not name a Doctrine
 * association on the entity — or a non-entity model the entity manager does not
 * manage — is treated as loaded, so the predicate never changes behaviour for a
 * relation it cannot reason about.
 *
 * Wired (through core's {@see \haddowg\JsonApi\Server\Server::withRelationshipLoadState()})
 * only on a Doctrine kernel; absent otherwise, in which case core treats every
 * relation as loaded.
 */
final class DoctrineRelationshipLoadState implements RelationshipLoadStateInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function isRelationshipLoaded(mixed $model, RelationInterface $relation): bool
    {
        // A to-one (proxy or hydrated) carries its identifier already; reading
        // the linkage never triggers a load, so it is always "loaded".
        if (!$relation->isToMany()) {
            return true;
        }

        if (!\is_object($model)) {
            return true;
        }

        $association = $relation->column();
        if ($association === null) {
            return true;
        }

        $metadata = $this->metadataFor($model);
        if ($metadata === null || !$metadata->isCollectionValuedAssociation($association)) {
            // Not a Doctrine to-many association we can reason about: do not
            // change behaviour — report loaded so linkage emits as it always has.
            return true;
        }

        // getReflectionProperty() spans the whole supported doctrine/orm ^3.0
        // range; the non-deprecated getPropertyAccessor() only exists in newer
        // 3.x, so it cannot be used while ORM 3.0 is supported. The deprecation is
        // docblock-only (no runtime notice); revisit when the ORM floor rises.
        $reflection = $metadata->getReflectionProperty($association);
        if ($reflection === null || !$reflection->isInitialized($model)) {
            return true;
        }

        $collection = $reflection->getValue($model);

        // A PersistentCollection is an AbstractLazyCollection: isInitialized()
        // answers without iterating or initialising it. Any other Collection
        // (e.g. an ArrayCollection on a not-yet-persisted entity, or an already
        // eager-fetched association) is materialised, so it counts as loaded.
        if ($collection instanceof AbstractLazyCollection) {
            return $collection->isInitialized();
        }

        return true;
    }

    /**
     * The Doctrine metadata for `$model`, or null when the entity manager does
     * not manage its class (a non-entity model the predicate must not touch).
     *
     * @return \Doctrine\ORM\Mapping\ClassMetadata<object>|null
     */
    private function metadataFor(object $model): ?\Doctrine\ORM\Mapping\ClassMetadata
    {
        try {
            return $this->entityManager->getClassMetadata($model::class);
        } catch (PersistenceMappingException) {
            return null;
        }
    }
}
