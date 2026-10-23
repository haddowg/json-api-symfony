<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApi\Server\Server;

/**
 * Applies the related endpoint's scope to the linkage a write sends (bundle ADR 0126),
 * before the persister resolves it.
 *
 * Each identifier whose type is scoped (the read side's decision, {@see RelatedReadRouter})
 * must be served by that type's provider, as `GET /{type}/{id}` would serve it; one that
 * is not is rejected exactly as a resource that does not exist. A to-many replace on an
 * existing parent keeps the members the scope hides, so it changes only the members the
 * client can see. Identifiers of an unscoped type pass through untouched.
 *
 * @internal
 */
final class ScopedLinkageGuard
{
    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly RelatedReadRouter $router,
    ) {}

    /**
     * The linkage the persister applies: `$linkage` itself, or on a to-many replace of an
     * existing parent, `$linkage` plus the current members the scope hides.
     *
     * @throws ResourceNotFound when the linkage names a resource its scoped type does not serve
     */
    public function scope(
        Server $server,
        object $parent,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        JsonApiRequestInterface $request,
        bool $creating = false,
    ): ToOneRelationship|ToManyRelationship {
        $identifiers = $linkage instanceof ToManyRelationship
            ? $linkage->resourceIdentifiers
            : \array_filter([$linkage->resourceIdentifier]);

        $sent = [];
        foreach ($identifiers as $identifier) {
            \assert($identifier instanceof ResourceIdentifier);
            if ($identifier->id === null) {
                continue;
            }

            if (!$this->isVisible($identifier->type, $identifier->id, $relation)) {
                throw new ResourceNotFound();
            }
            $sent[$identifier->type . "\0" . $identifier->id] = true;
        }

        // A new parent holds no members yet, and add and remove name every member they touch.
        if ($creating || $mode !== Mode::Replace || !$linkage instanceof ToManyRelationship) {
            return $linkage;
        }

        $hidden = [];
        foreach ($this->members($relation->readValue($parent, $request)) as $member) {
            $serializer = $relation->resolveSerializer($member, $server);
            if ($serializer === null) {
                continue;
            }

            $type = $serializer->getType($member);
            $id = $serializer->getId($member);
            if (!isset($sent[$type . "\0" . $id]) && !$this->isVisible($type, $id, $relation)) {
                $hidden[] = new ResourceIdentifier($type, $id);
            }
        }

        return $hidden === [] ? $linkage : new ToManyRelationship([...$linkage->resourceIdentifiers, ...$hidden]);
    }

    /**
     * `$existingPivot` without the rows of the members `$linkage` sends that the scope
     * hides, so a hidden member's pivot validates as a new member's does, the way a
     * missing one would.
     *
     * @param array<string, array<string, mixed>> $existingPivot the relation's existing pivot rows, by related id
     *
     * @return array<string, array<string, mixed>>
     */
    public function visiblePivot(RelationInterface $relation, array $existingPivot, ToManyRelationship $linkage): array
    {
        foreach ($linkage->resourceIdentifiers as $identifier) {
            \assert($identifier instanceof ResourceIdentifier);
            $id = $identifier->id;
            if ($id !== null && isset($existingPivot[$id]) && !$this->isVisible($identifier->type, $id, $relation)) {
                unset($existingPivot[$id]);
            }
        }

        return $existingPivot;
    }

    /**
     * The members `$parent`'s relationship endpoint serves for a scoped monomorphic
     * to-many, unpaginated, read through the related provider as `GET` on it reads them;
     * `null` for any other relation.
     *
     * @return list<object>|null
     */
    public function scopedMembers(object $parent, RelationInterface $relation, JsonApiRequestInterface $request): ?array
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$relation->isToMany() || \count($relation->relatedTypes()) !== 1 || !$this->router->isScoped($relation)) {
            return null;
        }

        $provider = $this->providers->forType($relatedType);
        $criteria = new CollectionCriteria(new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: []));
        $result = $provider instanceof PivotAwareProviderInterface && $provider->supportsPivot($relatedType, $relation)
            ? $provider->fetchRelatedPivotCollection($relatedType, $parent, $relation, $criteria, $request)
            : $provider->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);

        return $this->members($result->items);
    }

    /**
     * Whether `$relation` is a polymorphic to-many reaching a scoped type. Core reads its
     * linkage off the model, so its relationship endpoint cannot render the scoped set.
     */
    public function isScopedPolymorphicToMany(RelationInterface $relation): bool
    {
        if (!$relation->isToMany() || \count($relation->relatedTypes()) < 2) {
            return false;
        }

        foreach ($relation->relatedTypes() as $type) {
            if ($this->router->isScopedType($type, $relation)) {
                return true;
            }
        }

        return false;
    }

    private function isVisible(string $type, string $id, RelationInterface $relation): bool
    {
        return !$this->router->isScopedType($type, $relation)
            || $this->providers->forType($type)->fetchOne($type, $id) !== null;
    }

    /**
     * @return list<object>
     */
    private function members(mixed $value): array
    {
        if (!\is_iterable($value)) {
            return [];
        }

        $members = [];
        foreach ($value as $member) {
            if (\is_object($member)) {
                $members[] = $member;
            }
        }

        return $members;
    }
}
