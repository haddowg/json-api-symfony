<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The players again, with no id encoder, so a key kept in capitals renders in lower case.
 */
#[AsJsonApiResource(entity: PifPlayer::class)]
final class PifSquadResource extends AbstractResource
{
    public static string $type = 'pif-squads';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsToMany::make('teams', 'pif-teams')
                ->through(PifSeat::class)
                ->fields(Str::make('role')->nullable()->build())
                ->withData()
                ->paginate(PagePaginator::make())
                ->countable(),
        ];
    }
}
