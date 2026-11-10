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
 * stored pairs, otherwise the relation's value, each object through its serializer and a
 * scalar as an id of the single related type.
 *
 * @internal
 */
final class CurrentMembers
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function of(Server $server, object $parent, RelationInterface $relation, JsonApiRequestInterface $request): array
    {
        $members = [];
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
}
