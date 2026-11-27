<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApiBundle\Serializer\PivotMetaSerializer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PivotMetaSerializerTest extends TestCase
{
    #[Test]
    public function aReadableFieldMissingFromTheMembersValuesRendersNull(): void
    {
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(
            Integer::make('position')->build(),
            Str::make('label')->build(),
        )->build();

        $meta = $this->serializer($relation, ['t7' => ['position' => 1]])
            ->getMeta(['id' => 't7'], $this->createStub(JsonApiRequestInterface::class));

        self::assertSame(['pivot' => ['position' => 1, 'label' => null]], $meta);
    }

    #[Test]
    public function aHiddenWhenPredicateReceivesTheRelatedMember(): void
    {
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(
            Integer::make('position')->build(),
            Str::make('label')->hidden(
                static fn(mixed $member, JsonApiRequestInterface $request): bool => \is_array($member) && $member['id'] === 't8',
            )->build(),
        )->build();

        $serializer = $this->serializer($relation, [
            't7' => ['position' => 1, 'label' => 'a'],
            't8' => ['position' => 2, 'label' => 'b'],
        ]);
        $request = $this->createStub(JsonApiRequestInterface::class);

        self::assertSame(['pivot' => ['position' => 1, 'label' => 'a']], $serializer->getMeta(['id' => 't7'], $request));
        self::assertSame(['pivot' => ['position' => 2]], $serializer->getMeta(['id' => 't8'], $request));
    }

    #[Test]
    public function aMemberWithNoPivotRowRendersEachStoredFieldAsNull(): void
    {
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position'), Str::make('label'))->build();
        $serializer = $this->serializer($relation, ['t7' => ['position' => 1, 'label' => 'a']]);

        self::assertSame('{"pivot":{"position":null,"label":null}}', \json_encode($serializer->getMeta(['id' => 't9'], $this->createStub(JsonApiRequestInterface::class))));
    }

    #[Test]
    public function aMemberWhoseEveryPivotFieldIsHiddenRendersAnEmptyObject(): void
    {
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position')->hidden(static fn(): bool => true))->build();
        $serializer = $this->serializer($relation, ['t7' => ['position' => 1]]);

        self::assertSame('{"pivot":{}}', \json_encode($serializer->getMeta(['id' => 't7'], $this->createStub(JsonApiRequestInterface::class))));
    }

    #[Test]
    public function aRelationDeclaringOnlyAdditionalPropertiesRendersNoPivot(): void
    {
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(AdditionalProperties::make())->build();
        $serializer = $this->serializer($relation, ['t7' => ['extra' => 1]]);

        self::assertSame([], $serializer->getMeta(['id' => 't7'], $this->createStub(JsonApiRequestInterface::class)));
    }

    /**
     * @param array<string, array<string, mixed>> $pivotMap
     */
    private function serializer(BelongsToMany $relation, array $pivotMap): PivotMetaSerializer
    {
        $inner = $this->createStub(SerializerInterface::class);
        $inner->method('getMeta')->willReturn([]);
        $inner->method('getId')->willReturnCallback(static function (mixed $object): string {
            \assert(\is_array($object) && \is_string($object['id']));

            return $object['id'];
        });

        return new PivotMetaSerializer($inner, $pivotMap, $relation);
    }
}
