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
use haddowg\JsonApiBundle\Validation\JsonPointerBuilder;
use haddowg\JsonApiBundle\Validation\RelationshipTypeUnacceptable;

/**
 * Applies the related endpoint's scope to the linkage a write sends (bundle ADR 0126),
 * before the persister resolves it.
 *
 * Each identifier must name one of the relation's related types, whether or not the
 * validator bridge is installed. Each identifier whose type is scoped (the read side's decision, {@see RelatedReadRouter})
 * must be served by that type's provider, as `GET /{type}/{id}` would serve it; one that
 * is not is rejected exactly as a resource that does not exist. A to-many replace on an
 * existing parent keeps the members the scope hides, so it changes only the members the
 * client can see. Identifiers of an unscoped type pass through untouched.
 *
 * @internal
 */
final class ScopedLinkageGuard
{
    private readonly JsonPointerBuilder $pointers;

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly RelatedReadRouter $router,
    ) {
        $this->pointers = new JsonPointerBuilder();
    }

    /**
     * The linkage the persister applies: `$linkage` itself, or on a to-many replace of an
     * existing parent, `$linkage` plus the current members the scope hides.
     *
     * Every identifier must name one of the relation's related types; the persister
     * resolves each id under its identifier's type, so that is the type checked here.
     *
     * @param bool $atEndpoint whether the linkage is a relationship endpoint's body
     *                         (pointers at `/data`) rather than a member of a
     *                         resource's `data.relationships`
     *
     * @throws RelationshipTypeUnacceptable when an identifier names a type the relation does not accept
     * @throws ResourceNotFound             when the linkage names a resource its scoped type does not serve
     */
    public function scope(
        Server $server,
        object $parent,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        JsonApiRequestInterface $request,
        bool $creating = false,
        bool $atEndpoint = false,
    ): ToOneRelationship|ToManyRelationship {
        $identifiers = $linkage instanceof ToManyRelationship
            ? $linkage->resourceIdentifiers
            : \array_filter([$linkage->resourceIdentifier]);

        $this->assertAcceptedTypes($relation, $linkage, $atEndpoint);

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
            if ($id === null || !isset($existingPivot[$id])) {
                continue;
            }

            // Pivot rows are keyed by id alone: a member sent under a type the relation
            // does not accept is no existing member (the write is refused later).
            if (!$this->accepts($relation, $identifier->type) || !$this->isVisible($identifier->type, $id, $relation)) {
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
    public function scopedMembers(Server $server, object $parent, RelationInterface $relation, JsonApiRequestInterface $request): ?array
    {
        $relatedType = $relation->relatedTypes()[0] ?? null;
        if ($relatedType === null || !$relation->isToMany() || !Polymorphism::isMonomorphic($relation) || !$this->router->isScoped($relation)) {
            return null;
        }

        $criteria = new CollectionCriteria(new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: []));
        // A computed relation's members are its closure's value, narrowed as GET narrows them.
        if ($this->router->readsComputed($relation)) {
            return $this->members($this->router->fetchComputed($server, $parent, $relation, $criteria, $request)->items);
        }

        $provider = $this->providers->forType($relatedType);
        $result = $provider instanceof PivotAwareProviderInterface && $provider->supportsPivot($relatedType, $relation)
            ? $provider->fetchRelatedPivotCollection($relatedType, $parent, $relation, $criteria, $request)
            : $provider->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);

        return $this->members($result->items);
    }

    /**
     * @throws RelationshipTypeUnacceptable when an identifier names a type the relation does not accept
     */
    private function assertAcceptedTypes(RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, bool $atEndpoint): void
    {
        $identifiers = $linkage instanceof ToManyRelationship
            ? \array_values($linkage->resourceIdentifiers)
            : [$linkage->resourceIdentifier];

        $errors = [];
        foreach ($identifiers as $index => $identifier) {
            if (!$identifier instanceof ResourceIdentifier || $this->accepts($relation, $identifier->type)) {
                continue;
            }

            $index = $linkage instanceof ToManyRelationship ? $index : null;
            $errors[] = RelationshipTypeUnacceptable::error(
                $relation,
                $identifier->type,
                $atEndpoint
                    ? $this->pointers->forRelationshipEndpointLinkageType($index)
                    : $this->pointers->forLinkageType($relation->name(), $index),
            );
        }

        if ($errors !== []) {
            throw new RelationshipTypeUnacceptable($errors);
        }
    }

    private function accepts(RelationInterface $relation, string $type): bool
    {
        return \in_array($type, $relation->relatedTypes(), true);
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
