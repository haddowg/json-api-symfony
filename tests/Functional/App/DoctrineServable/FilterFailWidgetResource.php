<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Guard A3 (filter) FAIL: a filter targeting a column (`phantom`) that is not a
 * field or association on {@see GuardWidgetEntity}. The Doctrine handler would emit
 * `resource.phantom` and throw a `QueryException` at request time.
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineServableWarmer} must
 * reject it at `cache:warmup` instead.
 */
#[AsJsonApiResource(entity: GuardWidgetEntity::class)]
final class FilterFailWidgetResource extends AbstractResource
{
    public static string $type = 'widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('phantom', 'phantom'),
        ];
    }
}
