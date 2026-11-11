<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Filter\HasDefaultValue;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiBundle\DataProvider\RelationCriteriaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation that drops every default its related type declares has no filter default
 * left, so an unordered, unscoped one reads its members off the model again.
 */
#[CoversClass(RelationCriteriaFactory::class)]
final class RelationCriteriaFactoryFilterDefaultsTest extends TestCase
{
    #[Test]
    public function aRelationKeepsOnlyTheDefaultsItDoesNotDrop(): void
    {
        $factory = new RelationCriteriaFactory();
        $tracks = new class extends AbstractResource {
            public static string $type = 'tracks';

            public function fields(): array
            {
                return [Id::make()];
            }

            public function filters(): array
            {
                return [
                    Where::make('explicit')->asBoolean()->default(false),
                    Where::make('archived')->asBoolean()->default(false),
                ];
            }
        };

        self::assertTrue($factory->hasFilterDefaults($tracks, HasMany::make('tracks', 'tracks')->build()));
        self::assertTrue($factory->hasFilterDefaults($tracks, HasMany::make('tracks', 'tracks')->withoutFilterDefaults('explicit')->build()));
        self::assertFalse($factory->hasFilterDefaults($tracks, HasMany::make('tracks', 'tracks')->withoutFilterDefaults('explicit', 'archived')->build()));
        self::assertFalse($factory->hasFilterDefaults($tracks, HasMany::make('tracks', 'tracks')->withoutFilterDefaults()->build()));
        self::assertTrue($factory->hasFilterDefaults($tracks, HasMany::make('tracks', 'tracks')->withoutFilterDefaults()->withFilters(Where::make('explicit')->asBoolean()->default(false))->build()));
    }

    #[Test]
    public function theDroppedFilterStaysInTheVocabularyWithoutItsDefault(): void
    {
        $tracks = new class extends AbstractResource {
            public static string $type = 'tracks';

            public function fields(): array
            {
                return [Id::make()];
            }

            public function filters(): array
            {
                return [Where::make('explicit')->asBoolean()->default(false)];
            }
        };

        $filters = (new RelationCriteriaFactory())->criteriaFor(
            new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: []),
            $tracks,
            HasMany::make('tracks', 'tracks')->withoutFilterDefaults()->build(),
            null,
            includePivotFields: false,
        )->filters;

        self::assertCount(1, $filters);
        self::assertInstanceOf(Where::class, $filters[0]);
        self::assertSame('explicit', $filters[0]->key());
        self::assertInstanceOf(HasDefaultValue::class, $filters[0]);
        self::assertFalse($filters[0]->hasDefault());
    }
}
