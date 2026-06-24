<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

/**
 * The resolved authorization declarations for one JSON:API type via
 * `#[AsJsonApiResource(security: …)]` (bundle ADR 0043): the default plus the
 * per-operation overrides. Each is a Symfony Security ExpressionLanguage **string**
 * (enforced + documented secured), the bool **`true`** (documented secured only — an
 * external firewall enforces it), the bool **`false`** (documented public), or `null`
 * (inherit / ungated by this layer).
 *
 * Resolution per operation falls back to the {@see $default} when the override is
 * `null` (a bool is terminal — it does not fall back), so
 * `security: "is_granted('ROLE_ADMIN')"` gates every write/read unless a
 * `securityCreate`/… overrides it. Only a string is fed to the AuthorizationChecker by
 * the {@see ResourceSecuritySubscriber}; a bool is documentation-only.
 */
final readonly class ResourceSecurity
{
    public function __construct(
        public string|bool|null $default = null,
        public string|bool|null $create = null,
        public string|bool|null $update = null,
        public string|bool|null $delete = null,
        public string|bool|null $read = null,
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
     * The security for create (expression / `true` / `false`), falling back to
     * {@see $default} when unset.
     */
    public function forCreate(): string|bool|null
    {
        return $this->create ?? $this->default;
    }

    /**
     * The security for update **and** relationship mutation, falling back to
     * {@see $default} when unset.
     */
    public function forUpdate(): string|bool|null
    {
        return $this->update ?? $this->default;
    }

    /**
     * The security for delete, falling back to {@see $default} when unset.
     */
    public function forDelete(): string|bool|null
    {
        return $this->delete ?? $this->default;
    }

    /**
     * The security for a single-resource read, falling back to {@see $default} when unset.
     */
    public function forRead(): string|bool|null
    {
        return $this->read ?? $this->default;
    }
}
