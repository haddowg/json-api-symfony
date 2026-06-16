<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer\TrackSerializer;

/**
 * The `tracks` resource type, mapped to its backing {@see Track} entity.
 *
 * It is the **serializer-override witness** (ADR 0023): `serializer:
 * TrackSerializer::class` delegates reads to a hand-written serializer (with a
 * bound constructor arg, proving DI resolution) while this resource still hydrates
 * writes — read and write capabilities resolved independently.
 *
 * Field/relation declarations are re-themed verbatim from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Resource/TrackResource.php TrackResource}:
 * an `ArrayList` with per-item rules; a `storedAs()` column rename
 * (`durationSeconds` ← `length_seconds`); a computed read-only `displayTitle`; a
 * `like` text filter on `title`; a `belongsTo` to-one (`album`) and a plain
 * `belongsToMany` to-many (`playlists`) that prohibits full replacement.
 */
#[AsJsonApiResource(entity: Track::class, serializer: TrackSerializer::class)]
final class TrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->sortable(),
            Integer::make('trackNumber')->min(1)->sortable(),
            // The JSON:API member `durationSeconds` is stored on the entity's
            // `length_seconds` column — a storedAs() rename round-trips transparently.
            Integer::make('durationSeconds')->storedAs('length_seconds'),
            Boolean::make('explicit'),
            // An array attribute with per-item rules: at least one genre, each a
            // non-empty string, no duplicates.
            ArrayList::make('genres')
                ->minItems(1)
                ->each(new MinLength(1))
                ->uniqueItems(),
            Time::make('previewOffset')->nullable(),
            // Computed: no backing column, derived across trackNumber + title on read.
            Str::make('displayTitle')
                ->computed()
                ->readOnly()
                ->extractUsing(static fn(mixed $track): string => $track instanceof Track
                    ? \sprintf('%d. %s', $track->trackNumber, $track->title)
                    : ''),

            // Default relation reader: `album` reads the ManyToOne and `playlists`
            // the ManyToMany straight off the entity associations. `playlists` is a
            // PLAIN join table — it carries no pivot data; the pivot-bearing variant
            // is the playlists resource's `orderedTracks` relation.
            BelongsTo::make('album')->type('albums'),
            BelongsToMany::make('playlists')
                ->type('playlists')
                ->cannotReplace(),
        ];
    }

    public function filters(): array
    {
        // `like`: a case-insensitive substring match on title (the operator is the
        // third make() argument). `explicit` coerces to a real bool, defaulting to
        // false when absent. `genres` matches a membership set.
        //
        // `explicit` also declares a `->boolean()` value constraint (core ADR 0055,
        // bundle ADR 0048): a client-supplied `filter[explicit]` must be a boolean
        // wire form (`true`/`false`/`1`/`0`), so `filter[explicit]=banana` is a clean
        // `400` (`FILTER_VALUE_INVALID`, `source.parameter`) on the primary
        // collection rather than silently coercing to `false` and mis-matching. The
        // author-set `default(false)` is trusted and never validated.
        return [
            Where::make('title', 'title', 'like'),
            Where::make('explicit')->asBoolean()->default(false)->boolean(),
            WhereIn::make('genres'),
        ];
    }
}
