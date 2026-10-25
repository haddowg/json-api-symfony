<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Server;

use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApiBundle\Server\RelationsProviderInterface;
use haddowg\JsonApiBundle\Server\RelationsRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RelationsRegistryTest extends TestCase
{
    #[Test]
    public function eachServerSeesOnlyItsOwnProvider(): void
    {
        $default = $this->provider('author');
        $named = $this->provider('editor');
        $registry = new RelationsRegistry(new ServiceLocator([
            RelationsRegistry::key('default', 'notes') => static fn() => $default,
            RelationsRegistry::key('v5', 'notes') => static fn() => $named,
            'notes' => static fn() => $default,
        ]));

        self::assertSame(['author'], $this->names($registry->relationsFor('notes', 'default')));
        self::assertSame(['editor'], $this->names($registry->relationsFor('notes', 'v5')));
        self::assertNull($registry->relationsFor('notes', 'v6'));
        self::assertSame(['author'], $this->names($registry->relationsFor('notes')));
    }

    private function provider(string $relation): RelationsProviderInterface
    {
        return new class ($relation) implements RelationsProviderInterface {
            public function __construct(private readonly string $relation) {}

            public function relations(): array
            {
                return [BelongsTo::make($this->relation, 'notes')->build()];
            }
        };
    }

    /**
     * @param list<\haddowg\JsonApi\Resource\Field\RelationInterface>|null $relations
     *
     * @return list<string>
     */
    private function names(?array $relations): array
    {
        self::assertNotNull($relations);

        return \array_map(static fn($relation): string => $relation->name(), $relations);
    }
}
