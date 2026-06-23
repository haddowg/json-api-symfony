<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The SPI on-ramp witness (C1): a minimal {@see AbstractDataProvider} subclass that
 * implements only the three read abstracts ({@see AbstractDataProvider::supports()} /
 * {@see AbstractDataProvider::fetchOne()} / {@see AbstractDataProvider::fetchCollection()})
 * is constructible, and every inherited relationship / batch / pivot default returns the
 * neutral "this capability is absent" value the caller treats as such — so a thin
 * provider never has to hand-stub the other six methods.
 */
final class AbstractDataProviderTest extends TestCase
{
    #[Test]
    public function aMinimalSubclassNeedOnlyImplementTheThreeReadAbstracts(): void
    {
        $provider = $this->minimalProvider();

        self::assertTrue($provider->supports('things'));
        self::assertNull($provider->fetchOne('things', '1'));
        self::assertSame([], \iterator_to_array($provider->fetchCollection('things', $this->criteria())->items));
    }

    #[Test]
    public function fetchRelatedCollectionDefaultsToAnEmptyUnwindowedResult(): void
    {
        $result = $this->minimalProvider()->fetchRelatedCollection(
            'others',
            new \stdClass(),
            $this->relation(),
            $this->criteria(),
            $this->request(),
        );

        self::assertSame([], \iterator_to_array($result->items));
        self::assertNull($result->total);
        self::assertFalse($result->windowed);
    }

    #[Test]
    public function fetchRelatedCollectionBatchDefaultsToAnEmptyBatch(): void
    {
        $batch = $this->minimalProvider()->fetchRelatedCollectionBatch(
            'things',
            [$this->parent('1')],
            $this->relation(),
            $this->criteria(),
            $this->request(),
        );

        // Every parent is absent from an empty batch, so for() fills the gap with an
        // empty result — the documented "a relation the provider cannot batch" value.
        self::assertSame([], \iterator_to_array($batch->for('1')->items));
    }

    #[Test]
    public function countRelatedDefaultsToAnEmptyCountMap(): void
    {
        $counts = $this->minimalProvider()->countRelated(
            'things',
            [$this->parent('1')],
            $this->relation(),
            $this->criteria(),
            $this->request(),
        );

        self::assertSame([], $counts);
    }

    #[Test]
    public function relatedToOneMatchesDefaultsToTrueSoAMatchingToOneIsNeverNulled(): void
    {
        // The crucial default: false here would null a *matching* to-one. A base
        // provider that does not filter its to-ones must never drop one.
        self::assertTrue($this->minimalProvider()->relatedToOneMatches(
            'others',
            new \stdClass(),
            $this->relation(),
            $this->criteria(),
            $this->request(),
        ));
    }

    #[Test]
    public function relatedToOneMatchesBatchDefaultsToTheAllMatchMap(): void
    {
        // An empty [] would null EVERY parent (absent-from-map === no-match); the
        // default is the full all-true map keyed by each parent's wire id.
        $matches = $this->minimalProvider()->relatedToOneMatchesBatch(
            'things',
            [$this->parent('7'), $this->parent('9')],
            $this->relation(),
            $this->criteria(),
            $this->request(),
        );

        self::assertSame(['7' => true, '9' => true], $matches);
    }

    #[Test]
    public function fetchRelationshipPivotDefaultsToAnEmptyMap(): void
    {
        self::assertSame(
            [],
            $this->minimalProvider()->fetchRelationshipPivot('things', new \stdClass(), $this->relation()),
        );
    }

    /**
     * @return AbstractDataProvider<object>
     */
    private function minimalProvider(): AbstractDataProvider
    {
        return new class extends AbstractDataProvider {
            public function supports(string $type): bool
            {
                return $type === 'things';
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

    private function criteria(): CollectionCriteria
    {
        return new CollectionCriteria(new QueryParameters([], [], [], [], []));
    }

    private function parent(string $id): object
    {
        return new class ($id) {
            public function __construct(public string $id) {}
        };
    }

    private function relation(): RelationInterface
    {
        $relation = $this->createStub(RelationInterface::class);
        $relation->method('relatedTypes')->willReturn(['others']);

        return $relation;
    }

    private function request(): JsonApiRequestInterface
    {
        return $this->createStub(JsonApiRequestInterface::class);
    }
}
