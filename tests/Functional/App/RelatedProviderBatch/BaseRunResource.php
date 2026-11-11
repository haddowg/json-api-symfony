<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Runs whose progress, advertisers and notes come from other providers. The run holds
 * none of them: no property, no getter, no extractUsing().
 */
abstract class BaseRunResource extends AbstractResource
{
    public static string $type = 'runs';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            HasOne::make('progress', 'runProgress')->withData(),
            HasMany::make('advertisers', 'runAdvertisers')->withData(),
            HasMany::make('notes', 'runNotes')->withData(),
        ];
    }
}
