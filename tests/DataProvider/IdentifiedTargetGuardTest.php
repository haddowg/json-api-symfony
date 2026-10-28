<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToBuilder;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\IdentifiedTargetGuard;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which types of an identified `MorphTo` must load before their pairs render (bundle
 * ADR 0163). The provider scopes are covered by the functional suites; these are the
 * security gates.
 */
final class IdentifiedTargetGuardTest extends TestCase
{
    #[Test]
    public function aTypeIsGuardedOnlyOnTheServerWhereItDeclaresAReadGate(): void
    {
        $guard = $this->guard(new ResourceSecurityRegistry(
            ['agencies' => ['read' => 'is_granted("VIEW", object)']],
            ['partner' => ['advertisers' => ['default' => 'is_granted("ROLE_PARTNER")']]],
        ));

        self::assertSame(['agencies'], $guard->guardedTypes($this->relation(), 'default'));
        self::assertSame(['advertisers'], $guard->guardedTypes($this->relation(), 'partner'));
    }

    #[Test]
    public function aDocumentationOnlyGateGuardsNothing(): void
    {
        $guard = $this->guard(new ResourceSecurityRegistry(['agencies' => ['read' => true]]));

        self::assertSame([], $guard->guardedTypes($this->relation(), 'default'));
    }

    #[Test]
    public function aRelationReadPermissionGuardsEveryType(): void
    {
        $guard = $this->guard(new ResourceSecurityRegistry());

        self::assertSame([], $guard->guardedTypes($this->relation(), 'default'));
        self::assertSame(
            ['advertisers', 'agencies'],
            $guard->guardedTypes($this->relation(static fn(MorphToBuilder $builder): MorphToBuilder => $builder->security(read: 'is_granted("ROLE_ADMIN")')), 'default'),
        );
    }

    #[Test]
    public function aTypeNoProviderServesIsNeverGuarded(): void
    {
        $guard = new IdentifiedTargetGuard(
            new DataProviderRegistry([new InMemoryDataProvider('advertisers', [])]),
            new ResourceSecurityRegistry(['agencies' => ['read' => 'is_granted("VIEW", object)']]),
        );

        self::assertSame([], $guard->guardedTypes($this->relation(), 'default'));
    }

    private function guard(ResourceSecurityRegistry $security): IdentifiedTargetGuard
    {
        return new IdentifiedTargetGuard(
            new DataProviderRegistry([new InMemoryDataProvider('advertisers', []), new InMemoryDataProvider('agencies', [])]),
            $security,
        );
    }

    /**
     * @param (\Closure(MorphToBuilder): MorphToBuilder)|null $configure
     */
    private function relation(?\Closure $configure = null): MorphTo
    {
        $builder = MorphTo::make('target', ['advertisers', 'agencies'])
            ->identifiedBy(static fn(mixed $parent): ?array => null);

        return ($configure !== null ? $configure($builder) : $builder)->build();
    }
}
