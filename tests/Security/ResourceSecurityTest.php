<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Security;

use haddowg\JsonApiBundle\Security\ResourceSecurity;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes the per-operation fallback of a {@see ResourceSecurity} expression
 * set (bundle ADR 0043): each operation resolves to its override, else the default;
 * a fully-null set is empty, so the registry never gates such a type.
 */
final class ResourceSecurityTest extends TestCase
{
    #[Test]
    public function eachOperationFallsBackToTheDefault(): void
    {
        $security = new ResourceSecurity(default: "is_granted('ROLE_USER')");

        self::assertSame("is_granted('ROLE_USER')", $security->forCreate());
        self::assertSame("is_granted('ROLE_USER')", $security->forUpdate());
        self::assertSame("is_granted('ROLE_USER')", $security->forDelete());
        self::assertSame("is_granted('ROLE_USER')", $security->forRead());
    }

    #[Test]
    public function anOverrideWinsOverTheDefaultForThatOperationOnly(): void
    {
        $security = new ResourceSecurity(
            default: "is_granted('ROLE_USER')",
            create: "is_granted('ROLE_ADMIN')",
            delete: "is_granted('ROLE_ADMIN')",
        );

        self::assertSame("is_granted('ROLE_ADMIN')", $security->forCreate());
        self::assertSame("is_granted('ROLE_ADMIN')", $security->forDelete());
        // update + read keep the default.
        self::assertSame("is_granted('ROLE_USER')", $security->forUpdate());
        self::assertSame("is_granted('ROLE_USER')", $security->forRead());
    }

    #[Test]
    public function aNullOperationLeavesItUngated(): void
    {
        // Only delete is gated; no default, so the rest are null (ungated).
        $security = new ResourceSecurity(delete: "is_granted('ROLE_ADMIN')");

        self::assertNull($security->forCreate());
        self::assertNull($security->forUpdate());
        self::assertNull($security->forRead());
        self::assertSame("is_granted('ROLE_ADMIN')", $security->forDelete());
        self::assertFalse($security->isEmpty());
    }

    #[Test]
    public function aFullyNullSetIsEmptyAndAbsentFromTheRegistry(): void
    {
        self::assertTrue((new ResourceSecurity())->isEmpty());

        $registry = new ResourceSecurityRegistry([
            'widgets' => [],
            'gadgets' => ['default' => "is_granted('ROLE_USER')"],
        ]);

        self::assertNull($registry->securityFor('widgets'));
        self::assertNull($registry->securityFor('unknown'));
        self::assertSame("is_granted('ROLE_USER')", $registry->securityFor('gadgets')?->forCreate());
    }
}
