<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataProviderRegistryTest extends TestCase
{
    #[Test]
    public function itReturnsTheProviderSupportingTheType(): void
    {
        $articles = $this->provider('articles');
        $comments = $this->provider('comments');

        $registry = new DataProviderRegistry([$articles, $comments]);

        self::assertSame($comments, $registry->forType('comments'));
    }

    #[Test]
    public function aTypeWithNoProviderIsAWiringError(): void
    {
        $registry = new DataProviderRegistry([$this->provider('articles')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No JSON:API data provider is registered for type "comments".');

        $registry->forType('comments');
    }

    private function provider(string $type): DataProviderInterface
    {
        return new class ($type) implements DataProviderInterface {
            public function __construct(private readonly string $type) {}

            public function supports(string $type): bool
            {
                return $type === $this->type;
            }

            public function fetchOne(string $type, string $id): ?object
            {
                return null;
            }

            public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
            {
                return new CollectionResult([]);
            }
        };
    }
}
