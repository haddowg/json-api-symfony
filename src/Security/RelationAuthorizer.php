<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RelationAuthorizerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Answers core's questions about the relation data it reaches outside a relation's own
 * endpoints, with the expressions {@see ResourceSecuritySubscriber}
 * evaluates at those endpoints.
 *
 * A rendered relationship carries its data only when the caller passes the relation's
 * `security(read:)` expression against the parent, as the related and relationship
 * reads require. A `null` read inherits the parent's read gate, which the caller passed
 * to see the parent, and `true`/`false` only document the endpoints, so all three render.
 * A to-many renders no data either when any type it relates to refuses the caller its
 * collection read gate, the check `GET /{type}` makes. A flattened
 * `on()` write needs the related type's update expression against the row it lands on,
 * and passes when that type declares none.
 *
 * The shared service is unbound; each server's factory binds its own copy to the server
 * through {@see forServer()}, which resolves the type of a polymorphic relation's row
 * and names the server whose declarations apply. The unbound service
 * reads the default server's.
 */
final class RelationAuthorizer implements RelationAuthorizerInterface
{
    /**
     * @var (\Closure(): ?Server)|null
     */
    private ?\Closure $resolver = null;

    private string $serverName = ServerProvider::DEFAULT_SERVER;

    public function __construct(
        private readonly ?AuthorizationCheckerInterface $authorizationChecker,
        private readonly ResourceSecurityRegistry $registry,
        private readonly ?ReadableRows $rows = null,
    ) {}

    /**
     * A copy for the server `$serverName` that resolves serializers through the server
     * `$resolver` returns. It is read lazily, because the server is built with the copy.
     *
     * @param \Closure(): ?Server $resolver
     */
    public function forServer(\Closure $resolver, string $serverName = ServerProvider::DEFAULT_SERVER): self
    {
        $self = clone $this;
        $self->resolver = $resolver;
        $self->serverName = $serverName;

        return $self;
    }

    /**
     * The server this copy is bound to.
     */
    public function serverName(): string
    {
        return $this->serverName;
    }

    public function canReadRelationship(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): bool
    {
        // A permission the relation declares replaces the related type's own gates.
        $read = $relation->securityRead();
        if (\is_bool($read)) {
            return true;
        }
        if (\is_string($read)) {
            return \is_object($model) && ($this->rows?->relationPermission($model, $relation) ?? $this->isGranted($read, $model));
        }

        // A list holds what its query returned, so a to-many is shown whole or not at
        // all: not when any type it relates to refuses the caller its collection.
        return !$relation->isToMany() || $this->rows?->refusedCollectionType($relation, $this->serverName) === null;
    }

    public function canWriteRelatedAttribute(mixed $owner, RelationInterface $relation, FieldInterface $field, mixed $model, JsonApiRequestInterface $request): bool
    {
        if (!\is_object($owner)) {
            return false;
        }

        $type = $this->ownerType($owner, $relation);
        if ($type === null) {
            return false;
        }

        $expression = $this->registry->securityFor($type, $this->serverName)?->forUpdate();

        return !\is_string($expression) || $this->isGranted($expression, $owner);
    }

    /**
     * The type of the row a flattened write lands on: the relation's only type, or for a
     * polymorphic relation the declared type whose serializer claims the row.
     */
    private function ownerType(object $owner, RelationInterface $relation): ?string
    {
        $types = $relation->relatedTypes();
        if (\count($types) === 1) {
            return $types[0];
        }

        $resolver = $this->resolver !== null ? ($this->resolver)() : null;
        if ($resolver === null) {
            return null;
        }

        foreach ($types as $type) {
            if ($resolver->hasSerializerFor($type) && $resolver->serializerFor($type)->getType($owner) === $type) {
                return $type;
            }
        }

        return null;
    }

    private function isGranted(string $expression, object $subject): bool
    {
        if ($this->authorizationChecker === null || !\class_exists(Expression::class)) {
            throw new \LogicException(\sprintf(
                'The JSON:API security expression "%s" cannot be evaluated: it needs symfony/security-core, '
                . 'symfony/expression-language and a configured firewall (the security.authorization_checker service).',
                $expression,
            ));
        }

        return $this->authorizationChecker->isGranted(new Expression($expression), $subject);
    }
}
