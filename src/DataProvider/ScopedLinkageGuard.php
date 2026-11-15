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
 * Checks the linkage a write sends against the related types' providers, before
 * anything is stored.
 *
 * Each identifier must name one of the relation's related types, whether or not the
 * validator bridge is installed, and a row its type's provider serves, as
 * `GET /{type}/{id}` would serve it. One that is not, because no row holds it or because
 * a scoped type's scope hides it ({@see RelatedReadRouter}), is rejected exactly as a
 * resource that does not exist, on every mode: a remove may name an existing resource
 * that is not a member, which changes nothing, but not one that does not exist. A type
 * no provider serves has nothing to check against. Each type is asked once per write,
 * through {@see FetchesByIdsInterface} or {@see AdmitsIdsInterface} where its provider
 * offers either. A to-many replace on an existing parent keeps the members the scope
 * hides, so it changes only the members the client can see.
 *
 * @internal
 */
final class ScopedLinkageGuard
{
    private readonly JsonPointerBuilder $pointers;

    /**
     * The rows the current write has found served, as `type\0id` keys, so a pivot's
     * pre-validation and the write's check share one read.
     *
     * @var array<string, true>
     */
    private array $found = [];

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly RelatedReadRouter $router,
    ) {
        $this->pointers = new JsonPointerBuilder();
    }

    /**
     * Starts a write: rows found for an earlier one aren't trusted, since it may have
     * removed them.
     */
    public function forget(): void
    {
        $this->found = [];
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
     * @throws ResourceNotFound             when the linkage names a resource its type's provider does not serve
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
        $byType = [];
        foreach ($identifiers as $identifier) {
            \assert($identifier instanceof ResourceIdentifier);
            if ($identifier->id === null) {
                continue;
            }

            $byType[$identifier->type][$identifier->id] = $identifier->id;
            $sent[$identifier->type . "\0" . $identifier->id] = true;
        }
        foreach ($byType as $type => $ids) {
            if (!$this->providers->supportsType($type)) {
                continue;
            }

            $ids = \array_values($ids);
            if (\count($this->served($server, $type, $ids, $request)) !== \count($ids)) {
                throw new ResourceNotFound();
            }
        }

        // A new parent holds no members yet, and add and remove name every member they touch.
        if ($creating || $mode !== Mode::Replace || !$linkage instanceof ToManyRelationship) {
            return $linkage;
        }

        $current = [];
        // An identified relation's current members are its stored pairs.
        if (RelatedReadRouter::identifiesMembers($relation)) {
            foreach ($relation->storedIdentifierList($parent, $request) as [$type, $key]) {
                if ($server->hasSerializerFor($type)) {
                    $current[] = [$type, RelatedReadRouter::wireId($server->serializerFor($type), $key)];
                }
            }
        } elseif (($pivotIds = CurrentMembers::throughPivot($this->providers, $parent, $relation)) !== null) {
            // A pivot's value holds its association rows, so its members are read through them.
            foreach ($pivotIds as $id) {
                $current[] = [$relation->relatedTypes()[0], $id];
            }
        } else {
            foreach ($this->members($relation->readValue($parent, $request)) as $member) {
                $serializer = $relation->resolveSerializer($member, $server);
                if ($serializer !== null) {
                    $current[] = [$serializer->getType($member), $serializer->getId($member)];
                }
            }
        }

        $candidates = [];
        foreach ($current as [$type, $id]) {
            if (!isset($sent[$type . "\0" . $id]) && $this->router->isScopedType($type, $relation)) {
                $candidates[$type][$id] = $id;
            }
        }

        $hidden = [];
        foreach ($candidates as $type => $ids) {
            $served = $this->served($server, $type, \array_values($ids), $request);
            foreach ($ids as $id) {
                if (!isset($served[$id])) {
                    $hidden[] = new ResourceIdentifier((string) $type, (string) $id);
                }
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
    public function visiblePivot(Server $server, RelationInterface $relation, array $existingPivot, ToManyRelationship $linkage, JsonApiRequestInterface $request): array
    {
        $byType = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            \assert($identifier instanceof ResourceIdentifier);
            $id = $identifier->id;
            if ($id === null) {
                continue;
            }

            // Pivot rows are keyed by id alone: a member sent under a type the relation
            // does not accept is no existing member (the write is refused later).
            if (!$this->accepts($relation, $identifier->type)) {
                unset($existingPivot[$id]);
            } elseif ($this->providers->supportsType($identifier->type)) {
                // Every member sent is read here, so the write's own check reads nothing more.
                $byType[$identifier->type][$id] = $id;
            }
        }

        foreach ($byType as $type => $ids) {
            $served = $this->served($server, $type, \array_values($ids), $request);
            if (!$this->router->isScopedType($type, $relation)) {
                continue;
            }
            foreach ($ids as $id) {
                if (!isset($served[$id])) {
                    unset($existingPivot[$id]);
                }
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
        if ($this->router->isComputed($relation)) {
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

    /**
     * The ids among `$ids`, wire ids of `$type`, whose rows its provider serves, as keys:
     * one batched read when the provider offers it, with each id the batch left out asked
     * again through `fetchOne()`, since a batch can leave out a row it cannot key. A row
     * the batch loads stays in the data layer's identity map, so a persister resolving it
     * afterwards reads no row again.
     *
     * @param list<string> $ids
     *
     * @return array<string, true>
     */
    private function served(Server $server, string $type, array $ids, JsonApiRequestInterface $request): array
    {
        $unread = \array_values(\array_filter($ids, fn(string $id): bool => !isset($this->found[$type . "\0" . $id])));
        if ($unread !== []) {
            $provider = $this->providers->forType($type);
            $batch = match (true) {
                $provider instanceof FetchesByIdsInterface && $server->hasSerializerFor($type) => \array_map(
                    static fn(object $row): string => $server->serializerFor($type)->getId($row),
                    $provider->fetchByIds($type, $unread, $request),
                ),
                $provider instanceof AdmitsIdsInterface => $provider->admitIds($type, $unread, $request),
                default => [],
            };
            $batch = \array_fill_keys($batch, true);

            foreach ($unread as $id) {
                if (isset($batch[$id]) || $provider->fetchOne($type, $id) !== null) {
                    $this->found[$type . "\0" . $id] = true;
                }
            }
        }

        $served = [];
        foreach ($ids as $id) {
            if (isset($this->found[$type . "\0" . $id])) {
                $served[$id] = true;
            }
        }

        return $served;
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
