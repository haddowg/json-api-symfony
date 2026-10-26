<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The `notes` rows on the `counted` server, whose default paginator always counts.
 * `parent` links to `notes`, which `counted` does not register, so only its declared
 * example id can fill the linkage. `children` inherits the count-free paginator of
 * `jottings`, and `countedChildren` declares its own that always counts.
 */
abstract class TallyResource extends AbstractResource
{
    public static string $type = 'tallies';

    public function fields(): array
    {
        return [
            Id::make()->example('5'),
            Str::make('title')->example('Groceries'),
            BelongsTo::make('parent', 'notes')->exampleId('9')->withoutRelatedEndpoint(),
            HasMany::make('children', 'jottings'),
            HasMany::make('countedChildren', 'jottings')
                ->storedAs('children')
                ->paginate(PagePaginator::make()->withCount()),
        ];
    }
}
