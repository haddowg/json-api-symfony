<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresRelationUriFieldName;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataPersister\Doctrine\DoctrineDataPersister;
use haddowg\JsonApiBundle\DataPersister\ReportsMovedMembersInterface;
use haddowg\JsonApiBundle\OpenApi\Metadata\RelationMetadata;

/**
 * Which operations the bundle's security layer can refuse, read from the declarations
 * {@see ResourceSecuritySubscriber}, {@see RelationAuthorizer} and
 * {@see ReadableRows} enforce and the firewall in front of each route
 * ({@see RouteFirewall}).
 *
 * A gate can refuse an operation when it is a security expression, which the
 * subscriber evaluates, or `true`, which says something outside the bundle enforces
 * it. An expression refusing an anonymous caller answers `401` rather than `403`, so
 * every gate can also require authentication.
 *
 * @internal
 */
final class OperationSecurityFacts
{
    public function __construct(
        private readonly ActionRegistry $actions,
        private readonly ?ResourceSecurityRegistry $security = null,
        private readonly ?RouteFirewall $firewall = null,
        private readonly ?DataPersisterRegistry $persisters = null,
    ) {}

    /**
     * @param list<TypeMetadataInterface> $types the server's types, to follow a flattened attribute's relation chain
     */
    public function canDenyAccess(string $server, array $types, ?TypeMetadataInterface $type, OperationKey $operation): bool
    {
        return $this->gated($server, $types, $type, $operation)
            || ($this->firewall?->restricts($server, $type?->type(), $operation, $this->relationSegment($type, $operation)) ?? false);
    }

    /**
     * @param list<TypeMetadataInterface> $types the server's types, to follow a flattened attribute's relation chain
     */
    public function canRequireAuthentication(string $server, array $types, ?TypeMetadataInterface $type, OperationKey $operation): bool
    {
        if ($this->gated($server, $types, $type, $operation)) {
            return true;
        }

        $segment = $this->relationSegment($type, $operation);

        return ($this->firewall?->restricts($server, $type?->type(), $operation, $segment) ?? false)
            || ($this->firewall?->authenticates($server, $type?->type(), $operation, $segment) ?? false);
    }

    /**
     * Whether a declared gate guards the operation. The batch has none of its own: each
     * entry is checked as the write it runs.
     *
     * @param list<TypeMetadataInterface> $types
     */
    private function gated(string $server, array $types, ?TypeMetadataInterface $type, OperationKey $operation): bool
    {
        if ($type === null) {
            return false;
        }

        $declared = $this->security?->securityFor($type->type(), $server);
        $relation = self::relation($type, $operation->relation);

        return match ($operation->kind) {
            OperationKind::FetchCollection => self::gates($declared?->forList()),
            OperationKind::FetchOne => self::gates($declared?->forRead()),
            OperationKind::Create => self::gates($declared?->forCreate()) || $this->writeGated($server, $types, $type, true),
            OperationKind::Update => self::gates($declared?->forUpdate()) || $this->writeGated($server, $types, $type, false),
            OperationKind::Delete => self::gates($declared?->forDelete()),
            OperationKind::FetchRelated, OperationKind::FetchRelationship => $relation !== null && (
                $relation->securityRead() !== null
                    ? self::gates($relation->securityRead())
                    // A to-many is refused when the caller may not list a type it relates to.
                    : self::gates($declared?->forRead()) || ($relation->isToMany() && $this->anyRelated($server, $relation, static fn(ResourceSecurity $s): string|bool|null => $s->forList()))
            ),
            OperationKind::UpdateRelationship, OperationKind::AddRelationship => $relation !== null && (
                self::gates($relation->securityMutate() ?? $declared?->forUpdate()) || $this->movesMembers($server, $type, $relation)
            ),
            OperationKind::RemoveRelationship => $relation !== null && self::gates($relation->securityMutate() ?? $declared?->forUpdate()),
            OperationKind::Action => $this->actionGated($server, $type, $operation, $declared),
            OperationKind::Atomic => false,
        };
    }

    /**
     * A create or update is also refused by a relation's own mutate gate on linkage the
     * body sets, a to-many member it moves from another parent, and the related type's
     * update gate on an attribute flattened onto a related row.
     *
     * @param list<TypeMetadataInterface> $types
     */
    private function writeGated(string $server, array $types, TypeMetadataInterface $type, bool $creating): bool
    {
        foreach ($type->relations() as $relation) {
            if (self::gates($relation->securityMutate()) || $this->movesMembers($server, $type, $relation)) {
                return true;
            }
        }

        foreach ($type->fields() as $field) {
            $via = $field->relatedVia();
            if ($via === null || $field->isReadOnly($creating)) {
                continue;
            }
            foreach (self::ownerTypes($types, $type, $via) as $owner) {
                if (\is_string($this->security?->securityFor($owner, $server)?->forUpdate())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a to-many write can take a member from another parent, which needs the
     * related type's update expression when the parent's own gate refuses: the type's
     * persister reports such moves.
     */
    private function movesMembers(string $server, TypeMetadataInterface $type, RelationMetadataInterface $relation): bool
    {
        if (!$relation->isToMany() || !$this->persisters?->supportsType($type->type())) {
            return false;
        }
        $persister = $this->persisters->forType($type->type());
        if (!$persister instanceof ReportsMovedMembersInterface
            || ($persister instanceof DoctrineDataPersister && $relation instanceof RelationMetadata && !$persister->canMoveMembers($type->type(), $relation->relation()))) {
            return false;
        }

        foreach ($relation->relatedTypes() as $related) {
            if (\is_string($this->security?->securityFor($related, $server)?->forUpdate())) {
                return true;
            }
        }

        return false;
    }

    /**
     * An action's own expression, and for a collection action taking filters the type's
     * collection read gate, which its filtered collection passes.
     */
    private function actionGated(string $server, TypeMetadataInterface $type, OperationKey $operation, ?ResourceSecurity $declared): bool
    {
        if ($operation->actionPath === null || $operation->actionScope === null) {
            return false;
        }

        $descriptor = $this->actions->descriptorFor(
            $server,
            $type->type(),
            \haddowg\JsonApiBundle\Action\ActionScope::{$operation->actionScope->name},
            $operation->actionPath,
        );
        if ($descriptor === null) {
            return false;
        }

        return self::gates($descriptor->security)
            || ($descriptor->takesFilters() && self::gates($declared?->forList()));
    }

    /**
     * @param \Closure(ResourceSecurity): (string|bool|null) $gate
     */
    private function anyRelated(string $server, RelationMetadataInterface $relation, \Closure $gate): bool
    {
        foreach ($relation->relatedTypes() as $related) {
            $declared = $this->security?->securityFor($related, $server);
            if ($declared !== null && \is_string($gate($declared))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The types a flattened attribute's relation chain can end on.
     *
     * @param list<TypeMetadataInterface> $types
     *
     * @return list<string>
     */
    private static function ownerTypes(array $types, TypeMetadataInterface $type, string $via): array
    {
        $current = [$type];
        foreach (\explode('.', $via) as $hop) {
            $next = [];
            foreach ($current as $owner) {
                $relation = self::relation($owner, $hop);
                foreach ($relation?->relatedTypes() ?? [] as $related) {
                    foreach ($types as $candidate) {
                        if ($candidate->type() === $related) {
                            $next[] = $candidate;
                        }
                    }
                }
            }
            $current = $next;
        }

        return \array_values(\array_unique(\array_map(static fn(TypeMetadataInterface $owner): string => $owner->type(), $current)));
    }

    private static function relation(TypeMetadataInterface $type, ?string $name): ?RelationMetadataInterface
    {
        foreach ($name === null ? [] : $type->relations() as $relation) {
            if ($relation->name() === $name) {
                return $relation;
            }
        }

        return null;
    }

    private function relationSegment(?TypeMetadataInterface $type, OperationKey $operation): ?string
    {
        $relation = $type === null ? null : self::relation($type, $operation->relation);

        return $relation instanceof DeclaresRelationUriFieldName ? $relation->uriFieldName() : $relation?->name();
    }

    /**
     * An expression the subscriber evaluates, or `true`, which something outside the
     * bundle enforces. `false` and `null` let every caller through.
     */
    private static function gates(string|bool|null $security): bool
    {
        return \is_string($security) || $security === true;
    }
}
