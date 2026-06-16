<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

/**
 * The resolved authorization expressions declared for one JSON:API type via
 * `#[AsJsonApiResource(security: …)]` (bundle ADR 0043): the default expression
 * plus the per-operation overrides. Each is a Symfony Security ExpressionLanguage
 * string; `null` means that operation is not gated by this layer.
 *
 * Resolution per operation falls back to the {@see $default} when the override is
 * `null`, so `security: "is_granted('ROLE_ADMIN')"` gates every write/read unless a
 * `securityCreate`/… overrides it. The result is what the
 * {@see ResourceSecuritySubscriber} feeds to the AuthorizationChecker.
 */
final readonly class ResourceSecurity
{
    public function __construct(
        public ?string $default = null,
        public ?string $create = null,
        public ?string $update = null,
        public ?string $delete = null,
        public ?string $read = null,
    ) {}

    /**
     * Whether this type declared any expression at all — a fully-null set means the
     * type is registered with no security, so the subscriber treats it as a no-op.
     */
    public function isEmpty(): bool
    {
        return $this->default === null
            && $this->create === null
            && $this->update === null
            && $this->delete === null
            && $this->read === null;
    }

    /**
     * The expression gating create, falling back to {@see $default}.
     */
    public function forCreate(): ?string
    {
        return $this->create ?? $this->default;
    }

    /**
     * The expression gating update **and** relationship mutation, falling back to
     * {@see $default}.
     */
    public function forUpdate(): ?string
    {
        return $this->update ?? $this->default;
    }

    /**
     * The expression gating delete, falling back to {@see $default}.
     */
    public function forDelete(): ?string
    {
        return $this->delete ?? $this->default;
    }

    /**
     * The expression gating a single-resource read, falling back to {@see $default}.
     */
    public function forRead(): ?string
    {
        return $this->read ?? $this->default;
    }
}
