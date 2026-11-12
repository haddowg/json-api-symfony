<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasManyBuilder;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

abstract class CmCompanyResource extends AbstractResource
{
    public const array TYPE_TO_CLASS = [
        'cm-agencies' => CmAgency::class,
        'cm-advertisers' => CmAdvertiser::class,
        'cm-publishers' => CmPublisher::class,
    ];

    public function getType(mixed $object): string
    {
        $type = \is_object($object) ? \array_search($object::class, self::TYPE_TO_CLASS, true) : false;

        return \is_string($type) ? $type : static::$type;
    }

    public function fields(): array
    {
        return [Id::make(), Str::make('name'), Str::make('code'), ...$this->relations()];
    }

    /**
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface|\haddowg\JsonApi\Resource\Field\FieldBuilderInterface>
     */
    protected function relations(): array
    {
        return [];
    }

    /**
     * A computed to-many holding the company itself, so every member of the type has
     * one peer.
     */
    protected function peers(): HasManyBuilder
    {
        return HasMany::make('peers', static::$type)->readOnly()->extractUsing(
            static fn(mixed $company): array => [$company],
        );
    }
}
