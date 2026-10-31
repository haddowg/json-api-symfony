<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Catalog;

use haddowg\JsonApi\Catalog\OperationSecurity;
use haddowg\JsonApi\Catalog\OperationSecuritySourceInterface;
use haddowg\JsonApi\Catalog\SecurityLevel;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;

/**
 * The security rule guarding each operation in the catalogue, read from the same
 * declarations the bundle enforces (core ADR 0180):
 *
 * - a CRUD operation: the type's `security*` declaration for it;
 * - a related or relationship read: the relation's read security, else the type's
 *   single-read security; a relationship write: the relation's mutate security, else the
 *   type's update security (bundle ADR 0100);
 * - an action: its own `security`;
 * - the Atomic Operations endpoint: none, since each entry is checked as its operation.
 *
 * A rule is {@see SecurityLevel::Entity} when it is an expression evaluated against the
 * resource that reads `object` or `subject`, {@see SecurityLevel::Type} when any other
 * expression or `true` guards it, and {@see SecurityLevel::None} for `false` or nothing.
 * The collection read is evaluated with no resource, so it is never `Entity`.
 */
final class OperationSecuritySource implements OperationSecuritySourceInterface
{
    public function __construct(
        private readonly ActionRegistry $actions,
        private readonly ?ResourceSecurityRegistry $security = null,
    ) {}

    public function securityOf(string $server, ?TypeMetadataInterface $type, OperationKey $key): OperationSecurity
    {
        if ($type === null) {
            return OperationSecurity::none();
        }

        $declared = $this->security?->securityFor($type->type(), $server);

        [$expression, $hasResource] = match ($key->kind) {
            OperationKind::FetchCollection => [$declared?->forList(), false],
            OperationKind::FetchOne => [$declared?->forRead(), true],
            OperationKind::Create => [$declared?->forCreate(), true],
            OperationKind::Update => [$declared?->forUpdate(), true],
            OperationKind::Delete => [$declared?->forDelete(), true],
            OperationKind::FetchRelated, OperationKind::FetchRelationship => [$this->relationSecurity($type, $key, read: true) ?? $declared?->forRead(), true],
            OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship => [$this->relationSecurity($type, $key, read: false) ?? $declared?->forUpdate(), true],
            OperationKind::Action => [$this->actionSecurity($server, $type->type(), $key), $key->actionScope === ActionScope::Resource],
            OperationKind::Atomic => [null, false],
        };

        return new OperationSecurity($expression, self::level($expression, $hasResource));
    }

    private static function level(string|bool|null $expression, bool $hasResource): SecurityLevel
    {
        return match (true) {
            \is_string($expression) => $hasResource && SecurityExpressionInspector::readsResource($expression) ? SecurityLevel::Entity : SecurityLevel::Type,
            $expression === true => SecurityLevel::Type,
            default => SecurityLevel::None,
        };
    }

    private function relationSecurity(TypeMetadataInterface $type, OperationKey $key, bool $read): string|bool|null
    {
        foreach ($type->relations() as $relation) {
            if ($relation->name() === $key->relation) {
                return $read ? $relation->securityRead() : $relation->securityMutate();
            }
        }

        return null;
    }

    private function actionSecurity(string $server, string $type, OperationKey $key): ?string
    {
        if ($key->actionPath === null || $key->actionScope === null) {
            return null;
        }

        return $this->actions->descriptorFor(
            $server,
            $type,
            \haddowg\JsonApiBundle\Action\ActionScope::{$key->actionScope->name},
            $key->actionPath,
        )?->security;
    }
}
