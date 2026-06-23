<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Guard A3 PASS: a legit configuration that must boot clean. `headline` is a
 * `computed()` field marked `sortable()` — so `allSorts()` first derives a
 * `SortByField('headline', 'headline')` — but a {@see sorts()} override keyed by the
 * SAME name supplies a REAL column (`title`), and a later entry wins in `allSorts()`,
 * so the RESOLVED sort column is `title` (a real field). The `byTitle` filter targets
 * the real `title` column. The guard validates the resolved column, so this resource
 * does NOT throw.
 */
#[AsJsonApiResource(entity: GuardWidgetEntity::class)]
final class SafeWidgetResource extends AbstractResource
{
    public static string $type = 'widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            Str::make('headline')->computed()->sortable(),
        ];
    }

    public function sorts(): array
    {
        // Override the computed `headline` sort with one backed by a REAL column.
        return [
            SortByField::make('headline', 'title'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('byTitle', 'title'),
        ];
    }
}
