<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\Link\DeclaredLinkBuilder;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\SeedManifest;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Query\FullTextSearch;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer\TrackSerializer;
use Symfony\Component\Clock\Clock;

/**
 * The `tracks` resource type, mapped to its backing {@see Track} entity.
 *
 * It is the **serializer-override witness**: `serializer:
 * TrackSerializer::class` delegates reads to a hand-written serializer (with a
 * bound constructor arg, proving DI resolution) while this resource still hydrates
 * writes — read and write capabilities resolved independently.
 *
 * It is registered on both servers because {@see AlbumResource} is, and an album's
 * `tracks` relation exposes `GET /albums/{id}/tracks` — that endpoint returns track
 * resource objects, so `tracks` has to exist on every server that serves albums.
 *
 * Field/relation declarations are re-themed verbatim from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Resource/TrackResource.php TrackResource}:
 * an `ArrayList` with per-item rules; a `storedAs()` column rename
 * (`durationSeconds` ← `length_seconds`); a computed read-only `displayTitle`; a
 * `like` text filter on `title`; a `belongsTo` to-one (`album`) and a plain
 * `belongsToMany` to-many (`playlists`) that prohibits full replacement.
 *
 * It is also the **pagination-menu witness**: {@see pagination()} offers page, cursor
 * and offset strategies on the primary `tracks` collection, and the client picks one
 * per request.
 */
#[AsJsonApiResource(entity: Track::class, server: ['default', 'admin'], serializer: TrackSerializer::class)]
final class TrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make()->example('1'),
            Str::make('title')->example('Come Together')->required()->sortable(),
            Integer::make('trackNumber')->example(1)->required()->min(1)->sortable(),
            // The JSON:API member `durationSeconds` is stored on the entity's
            // `length_seconds` column — a storedAs() rename round-trips transparently.
            Integer::make('durationSeconds')->example(259)->storedAs('length_seconds')->guaranteed(),
            Boolean::make('explicit')->example(false)->guaranteed(),
            // An array attribute with per-item rules: at least one genre, each a
            // non-empty string, no duplicates. There is no sensible default, so a
            // create must send a non-empty list and an update can't empty it.
            ArrayList::make('genres')->example(['rock'])
                ->required()
                ->minItems(1)
                ->each(new MinLength(1))
                ->uniqueItems(),
            Time::make('previewOffset')->example('00:00:30')->nullable(),
            // Computed: no backing column, derived across trackNumber + title on read.
            Str::make('displayTitle')->example('1. Come Together')
                ->computed()
                ->readOnly()
                ->extractUsing(static fn(mixed $track): string => $track instanceof Track
                    ? \sprintf('%d. %s', $track->trackNumber, $track->title)
                    : ''),

            // Default relation reader: `album` reads the ManyToOne and `playlists`
            // the ManyToMany straight off the entity associations. `playlists` is a
            // PLAIN join table — it carries no pivot data; the pivot-bearing variant
            // is the playlists resource's `orderedTracks` relation.
            BelongsTo::make('album', 'albums'),
            BelongsToMany::make('playlists', 'playlists')
                ->cannotReplace()
                // Countable so the server-default-fallback related
                // endpoint still emits a total — the existing example asserts one.
                ->countable()
                // A to-many is lazy by default; `withData()` opts this
                // one back to EAGER so its linkage identifiers render on a track read —
                // the deliberate contrast with AlbumResource's lazy `tracks`.
                ->withData(),
        ];
    }

    public function links(): array
    {
        return [self::previewLink()];
    }

    /**
     * The `preview` link: a link object with a media type and two meta members, left
     * out for a track with no duration. {@see TrackSerializer} renders the same
     * declaration, because it serializes tracks in place of this resource.
     */
    public static function previewLink(): DeclaredLinkBuilder
    {
        return DeclaredLink::make('preview')
            ->describedAs('A short audio preview of the track, valid until expiresAt.')
            ->type('audio/mpeg')
            ->example('https://cdn.example.com/previews/1.mp3')
            ->meta(
                Str::make('format')
                    ->describedAs('Audio encoding of the preview.')
                    ->example('mp3'),
                DateTime::make('expiresAt')
                    ->describedAs('When the preview URL stops working.')
                    ->example('2026-01-01T12:00:00+00:00'),
            )
            ->hrefUsing(static fn(mixed $track): ?string => $track instanceof Track && $track->length_seconds > 0
                ? 'https://cdn.example.com/previews/' . $track->id . '.mp3'
                : null)
            // The clock is Symfony's global one, so a test can pin it with a MockClock.
            ->metaUsing(static fn(): array => [
                'format' => 'mp3',
                'expiresAt' => Clock::get()->now()->modify('+1 hour'),
            ]);
    }

    /**
     * A {@see MultiPaginator} menu for `GET /tracks`. `page[kind]` names a strategy; a
     * key only one strategy reads (`after`/`before` for cursor, `offset`/`limit` for
     * offset) selects it without a kind; anything else falls back to `page`. The page
     * arm repeats the server default (count-free, capped at the configured max), so a
     * request that doesn't pick a strategy gets the same response as before the menu.
     *
     * The album and playlist relations that reach `tracks` declare their own
     * `paginate()`, so the menu stays on this collection alone.
     */
    public function pagination(?PaginatorInterface $serverDefault): PaginatorInterface
    {
        return MultiPaginator::make(
            PagePaginator::make()->withMaxPerPage(SeedManifest::MAX_PER_PAGE),
            CursorPaginator::make()->withMaxPerPage(SeedManifest::MAX_PER_PAGE),
            OffsetPaginator::make()->withMaxPerPage(SeedManifest::MAX_PER_PAGE),
        )->default('page');
    }

    public function filters(): array
    {
        // `like`: a case-insensitive substring match on title (the operator is the
        // third make() argument). `explicit` coerces to a real bool, defaulting to
        // false when absent. `genres` matches a membership set.
        //
        // `explicit` also declares a `->boolean()` value constraint
        // (docs/data-layer.md): a client-supplied `filter[explicit]` must be a boolean
        // wire form (`true`/`false`/`1`/`0`), so `filter[explicit]=banana` is a clean
        // `400` (`FILTER_VALUE_INVALID`, `source.parameter`) on the primary
        // collection rather than silently coercing to `false` and mis-matching. The
        // author-set `default(false)` is trusted and never validated.
        return [
            Where::make('title', 'title', 'like'),
            Where::make('explicit')->asBoolean()->default(false)->boolean(),
            WhereIn::make('genres'),
            // The shared `filter[q]` search key (see AlbumResource), here over the track title.
            FullTextSearch::make('q', ['title']),
        ];
    }
}
