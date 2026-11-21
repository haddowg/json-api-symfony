<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The targets a pivot row links to, served so the pivot-row filters reaching them have a type
 * whose rules apply.
 */
#[AsJsonApiResource(entity: PkPlaceholderTargetEntity::class, readOnly: true)]
final class PkPlaceholderTargetResource extends AbstractResource
{
    public static string $type = 'placeholder-targets';

    public function fields(): array
    {
        return [
            Id::make()->uuid(),
            Str::make('name'),
        ];
    }
}
