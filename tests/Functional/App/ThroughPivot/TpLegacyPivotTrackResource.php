<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Filter\FilterBuilderInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Tracks again, whose playlists declare one deprecated `pivot.`-prefixed filter, chosen by
 * {@see $declaration}, reaching the note a pivot row holds, which the warm-up refuses.
 */
#[AsJsonApiResource(entity: TpTrackEntity::class, readOnly: true)]
final class TpLegacyPivotTrackResource extends AbstractResource
{
    public static string $type = 'tpLegacyPivotTracks';

    public static string $declaration = '';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            BelongsToMany::make('playlists', 'tpPlaylists')
                ->through(TpEntryEntity::class)
                ->fields(Integer::make('position'))
                ->withFilters(...self::filtersFor(self::$declaration)),
        ];
    }

    /**
     * @return list<FilterInterface|FilterBuilderInterface>
     */
    private static function filtersFor(string $declaration): array
    {
        return match ($declaration) {
            'legacy-through-untyped', 'legacy-through-several' => [WhereThrough::make('noteText', 'pivot.note.text')],
            'legacy-through-in-untyped' => [WhereThroughIn::make('note', 'pivot.note')],
            'legacy-has-untyped' => [WhereHas::make('noted', 'pivot.note')],
            'legacy-doesnt-have-untyped' => [WhereDoesntHave::make('unnoted', 'pivot.note')],
            'legacy-grouped-untyped' => [WhereAny::make('search', WhereThrough::make('noteSearch', 'pivot.note.text'))],
            default => [],
        };
    }
}
