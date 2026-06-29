<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Artist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Query\FullTextSearch;

/**
 * The `artists` resource type, mapped to its backing {@see Artist} entity via
 * `#[AsJsonApiResource(entity: …)]` so the reference Doctrine provider serves it.
 *
 * Field/relation/filter declarations are re-themed verbatim from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Resource/ArtistResource.php ArtistResource}:
 * a singular filter (`filter[slug]` collapses zero-to-one), a computed read-only
 * `trackCount` attribute, create-vs-update read-only scoping
 * (`createdAt->readOnlyOnUpdate()`), and the two `hasOne`/`hasMany` relation
 * shapes with load-aware to-many linkage. (Core additionally registers a custom
 * `TrackCountSort`; that computed sort needs a hand-written sort handler — not a
 * Doctrine push-down — so it is reintroduced in a later slice, not the Foundation.)
 *
 * `securityRead: false` + `securityList: false` declare the artist catalogue
 * **fully public** — both `GET /artists/{id}` and `GET /artists` — even though the API
 * configures a document-level default (`bearer`). The booleans are documentation-only:
 * they emit the OpenAPI operation override `security: []` (no `401`) on each read,
 * overriding that default, and are not runtime gates (the example's `access_token`
 * firewall already allows the anonymous reads). Contrast the expression-gated writes on
 * `PlaylistResource` and the collection gate on the test suite's `ownedWidgets`.
 */
#[AsJsonApiResource(entity: Artist::class, securityRead: false, securityList: false)]
final class ArtistResource extends AbstractResource
{
    public static string $type = 'artists';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required()->maxLength(120)->sortable(),
            Str::make('slug')->sortable(),
            Url::make('website')->nullable(),
            Str::make('bio')->nullable()->maxLength(1000),
            // Computed: no backing column on the wire (computed() nulls it), so read
            // the value off the entity's trackCount property explicitly.
            Integer::make('trackCount')
                ->computed()
                ->readOnly()
                ->extractUsing(static fn(mixed $artist): int => $artist instanceof Artist ? $artist->trackCount : 0),
            DateTime::make('createdAt')->readOnlyOnUpdate()->sortable(),

            // Default relation reader: `albums` reads the OneToMany association
            // straight off the entity (load-aware linkage so a lazy collection emits
            // links-only without forcing a fetch). The IA-PLAN entity table maps the
            // artist with a single OneToMany `albums`, so — unlike core's in-memory
            // domain — there is no separate `featuredAlbum` to-one here.
            //
            // `albums` is includable (the back-reference of `albums.artist`): an
            // artist's discography is a natural `GET /artists/{id}?include=albums`, and
            // the include-path resolver bounds the depth of the enumerated paths, so the
            // back-and-forth `albums.artist` cycle does not run away. (Contrast bundle
            // ADR 0037's cannotBeIncluded() opt-out, demonstrated elsewhere.)
            HasMany::make('albums', 'albums'),
        ];
    }

    public function filters(): array
    {
        // singular(): GET /artists?filter[slug]=radiohead collapses a unique match
        // to a single resource object (or null), not a collection.
        return [
            Where::make('slug')->singular(),
            // The shared `filter[q]` search key (see AlbumResource) — here a multi-field
            // search across the artist's name and bio, the richest demo of the custom
            // FullTextSearch arm.
            FullTextSearch::make('q', ['name', 'bio']),
        ];
    }

    /**
     * Object-aware so this resource can participate in a polymorphic relation
     * (favorites→favoritable, libraries→items): only a real {@see Artist} is an
     * `artists` type.
     */
    public function getType(mixed $object): string
    {
        return $object instanceof Artist ? 'artists' : '';
    }
}
