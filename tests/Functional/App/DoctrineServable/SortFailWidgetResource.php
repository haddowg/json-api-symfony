<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Guard A3 (sort) FAIL: a `computed()` field (`summary`, no backing column) marked
 * `sortable()`. `allSorts()` derives a `SortByField` whose column defaults to the
 * field NAME (`summary`), which is not a column on {@see GuardWidgetEntity} — the
 * Doctrine handler would emit `resource.summary` and throw a `QueryException` at
 * request time. {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineServableWarmer}
 * must reject it at `cache:warmup` instead.
 */
#[AsJsonApiResource(entity: GuardWidgetEntity::class)]
final class SortFailWidgetResource extends AbstractResource
{
    public static string $type = 'widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            Str::make('summary')->computed()->sortable(),
        ];
    }
}
