<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Decides what of another type a response may show through a relation (bundle ADR 0140),
 * with the gates that type's own endpoints apply:
 *
 *  - a single related row (a to-one target) needs the type's read expression
 *    (`securityRead`, falling back to `security`) with the row as subject, the check
 *    `GET /{type}/{id}` makes;
 *  - a to-many needs the collection read expression (`securityList`, falling back to
 *    `security`) of every type it relates to, with no subject, the check `GET /{type}`
 *    makes. Its rows are never filtered one by one: which rows a list holds is the query's
 *    business.
 *
 * Answers are memoized per row and type, and per type for a collection, until the next
 * {@see bind()} or {@see forget()}.
 */
final class ReadableRows
{
    private ?SerializerResolverInterface $server = null;

    /**
     * @var \WeakMap<object, array<string, bool>>
     */
    private \WeakMap $memo;

    /**
     * @var array<string, bool>
     */
    private array $collections = [];

    public function __construct(
        private readonly ?AuthorizationCheckerInterface $authorizationChecker,
        private readonly ResourceSecurityRegistry $registry,
    ) {
        $this->memo = new \WeakMap();
    }

    /**
     * Sets the server whose serializers type a polymorphic relation's rows, and drops
     * every memoized answer.
     */
    public function bind(?SerializerResolverInterface $server): void
    {
        $this->server = $server;
        $this->forget();
    }

    /**
     * Drops every memoized answer, so a row a write just changed is decided again.
     */
    public function forget(): void
    {
        $this->memo = new \WeakMap();
        $this->collections = [];
    }

    /**
     * Whether any type `$relation` relates to declares a read expression, so its single
     * rows need checking at all.
     */
    public function guardsRows(RelationInterface $relation): bool
    {
        foreach ($relation->relatedTypes() as $type) {
            if (\is_string($this->registry->securityFor($type)?->forRead())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the caller may see the single row `$row`, reached through `$relation`.
     */
    public function allows(object $row, RelationInterface $relation): bool
    {
        $type = $this->typeOf($row, $relation);
        $expression = $type !== null ? $this->registry->securityFor($type)?->forRead() : null;
        if ($type === null || !\is_string($expression)) {
            return true;
        }

        $answers = $this->memo[$row] ?? [];
        if (!\array_key_exists($type, $answers)) {
            $answers[$type] = $this->isGranted($expression, $row);
            $this->memo[$row] = $answers;
        }

        return $answers[$type];
    }

    /**
     * The first type `$relation` relates to whose collection read gate refuses the
     * caller, or `null` when every one admits them.
     */
    public function refusedCollectionType(RelationInterface $relation): ?string
    {
        foreach ($relation->relatedTypes() as $type) {
            $expression = $this->registry->securityFor($type)?->forList();
            if (!\is_string($expression)) {
                continue;
            }

            $this->collections[$type] ??= $this->isGranted($expression, null);
            if (!$this->collections[$type]) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The type `$row` renders as: the relation's only type, or for a polymorphic relation
     * the declared type whose serializer claims it. Null when none does, in which case
     * nothing can render the row anyway.
     */
    private function typeOf(object $row, RelationInterface $relation): ?string
    {
        $types = $relation->relatedTypes();
        if (\count($types) === 1) {
            return $types[0];
        }

        if ($this->server === null) {
            return null;
        }

        return $relation->resolveSerializer($row, $this->server)?->getType($row);
    }

    private function isGranted(string $expression, ?object $subject): bool
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
