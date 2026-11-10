<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\ResourceIdentifier;
use haddowg\JsonApi\Server\Server;

/**
 * Reads the members a to-many relation holds on a loaded parent as `[type, id]` pairs
 * with wire ids, for {@see \haddowg\JsonApi\Resource\Field\GuardsMembershipInterface::assertMembershipUnchanged()}.
 *
 * It reads them as the relationship linkage renders them, so the check compares a
 * replacement with the members a client can read back: an identified `MorphToMany`'s
 * stored pairs, a pivot `belongsToMany`'s far members through its association entity,
 * hidden ones included, otherwise the relation's value, each object through its
 * serializer and a scalar as an id of the single related type.
 *
 * @internal
 */
final class CurrentMembers
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function of(Server $server, DataProviderRegistry $providers, object $parent, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        $members = [];
        $pivotIds = self::throughPivot($providers, $parent, $relation);
        if ($pivotIds !== null) {
            $type = $relation->relatedTypes()[0];
            foreach ($pivotIds as $id) {
                $members[] = [$type, $id];
            }

            return $members;
        }

        if (RelatedReadRouter::identifiesMembers($relation)) {
            foreach ($relation->storedIdentifierList($parent, $request) as [$type, $key]) {
                $members[] = [$type, $server->hasSerializerFor($type) ? RelatedReadRouter::wireId($server->serializerFor($type), $key) : (string) $key];
            }

            return $members;
        }

        $value = $relation->readValue($parent, $request);
        $single = \count($relation->relatedTypes()) === 1 ? $relation->relatedTypes()[0] : null;
        foreach (\is_iterable($value) ? $value : [] as $member) {
            if ($member instanceof ResourceIdentifier) {
                $members[] = [$member->type, (string) $member->id];
            } elseif (\is_object($member)) {
                $serializer = $relation->resolveSerializer($member, $server);
                if ($serializer !== null) {
                    $members[] = [$serializer->getType($member), $serializer->getId($member)];
                }
            } elseif ((\is_string($member) || \is_int($member)) && $single !== null) {
                $members[] = [$single, $server->hasSerializerFor($single) ? RelatedReadRouter::wireId($server->serializerFor($single), $member) : (string) $member];
            }
        }

        return $members;
    }

    /**
     * The wire ids of every far member a pivot `belongsToMany` reaches from `$parent`
     * through its association entity, unscoped, or `null` when the related provider
     * doesn't read `$relation` as a pivot. The relation's own value holds association
     * rows rather than members unless an `extractUsing()` maps them.
     *
     * @return list<string>|null
     */
    public static function throughPivot(DataProviderRegistry $providers, object $parent, RelationInterface $relation): ?array
    {
        $relatedType = $relation->isToMany() && \count($relation->relatedTypes()) === 1 ? $relation->relatedTypes()[0] : null;
        if ($relatedType === null || !$providers->supportsType($relatedType)) {
            return null;
        }

        $provider = $providers->forType($relatedType);
        if (!$provider instanceof PivotAwareProviderInterface || !$provider->supportsPivot($relatedType, $relation)) {
            return null;
        }

        return \array_map(\strval(...), \array_keys($provider->fetchRelatedPivotMap($relatedType, $parent, $relation)));
    }
}
