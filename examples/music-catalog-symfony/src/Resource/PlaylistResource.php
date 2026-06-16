<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Slug;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Uuid;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Playlist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Hook\HookAbortException;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Hydrator\PlaylistHydrator;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksTrait;

/**
 * The `playlists` resource type, mapped to its backing {@see Playlist} entity.
 *
 * It is the **hydrator-override witness** (ADR 0023): `hydrator:
 * PlaylistHydrator::class` delegates writes to a hand-written hydrator (with a
 * bound constructor arg, proving DI resolution) while this resource still
 * serializes reads.
 *
 * It is also the **UUID id-strategy** demonstrator (bundle ADR 0039):
 * `Id::make()->uuid()->generated()` keys the {@see Playlist} on a string UUID the
 * *app* mints when a create omits the id, while the custom hydrator additionally
 * accepts a well-formed client `id` (the `uuid()` format both pins the route `{id}`
 * shape and validates a client-supplied id on the wire).
 *
 * It is the **per-type lifecycle-hook witness** (bundle ADR 0042): by
 * implementing {@see ResourceLifecycleHooksInterface} (with no-op defaults from
 * {@see ResourceLifecycleHooksTrait}) and overriding two hooks it opts into the
 * lifecycle seam *without registering a subscriber* — the built-in
 * {@see \haddowg\JsonApiBundle\EventListener\ResourceHookSubscriber} routes the
 * matching events to these methods. {@see beforeCreate()} mutates the entity (a
 * before hook runs before the flush, so the change persists); {@see beforeDelete()}
 * is a guard that aborts by throwing. The cross-cutting *event* twin of this seam
 * lives on {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\EventListener\AuditLogSubscriber}.
 *
 * It is finally the **declarative-authorization witness** (bundle ADR 0043): the
 * `securityDelete`/`securityUpdate` expressions on the attribute gate two
 * operations through Symfony Security, evaluated at the lifecycle hooks by the
 * bundle's built-in {@see \haddowg\JsonApiBundle\Security\ResourceSecuritySubscriber}.
 *  - `securityDelete: "is_granted('ROLE_ADMIN')"` — only an admin may delete a
 *    playlist (a role gate);
 *  - `securityUpdate: "is_granted('EDIT', object)"` — only a playlist's *owner* may
 *    update it, delegating to {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Security\PlaylistOwnerVoter}
 *    (`object` is the loaded playlist, an ownership gate).
 * Create and read carry no expression, so they stay ungated (anyone may create or
 * read). A denial throws before the persister runs — nothing is written — and the
 * route-scoped ExceptionListener renders a JSON:API `403` (or `401` when
 * unauthenticated). See `docs/authorization.md`.
 *
 * It is finally the **pivot witness** (bundle ADRs 0045/0046): alongside the plain
 * `belongsToMany` `tracks` (a bare join table, no pivot columns), it declares a
 * second `belongsToMany` `orderedTracks` to the same `tracks` type backed by the
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\PlaylistEntry}
 * association entity, whose `position`/`addedAt` columns render as each member's
 * `meta.pivot` and are recognised as `?filter`/`?sort` keys on that relation's
 * related endpoint. `position` is declared a **writable** pivot field (so it is set
 * and reordered through the linkage `meta` on a write), while `addedAt` is
 * `readOnly()` (server-owned, stamped by the entity's `#[ORM\PrePersist]`). See
 * `docs/relationships.md` and the example README.
 *
 * Field/relation declarations are re-themed from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Resource/PlaylistResource.php PlaylistResource}:
 * a UUID id; a read-only `slug` derived from `title` by the custom hydrator; a
 * `belongsTo` owner; a plain `belongsToMany` `tracks`; and the pivot-backed
 * `belongsToMany` `orderedTracks`, both paginating two-per-page.
 */
#[AsJsonApiResource(
    entity: Playlist::class,
    hydrator: PlaylistHydrator::class,
    securityUpdate: "is_granted('EDIT', object)",
    securityDelete: "is_granted('ROLE_ADMIN')",
)]
final class PlaylistResource extends AbstractResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public static string $type = 'playlists';

    public function fields(): array
    {
        return [
            // A UUID id: the app mints a v4 UUID via generated() when a create omits
            // the id, and the custom hydrator also accepts a well-formed client UUID
            // (uuid() pins the route shape and validates a wire id).
            Id::make()->uuid()->generated(),
            Str::make('title')->required(),
            // Derived from title by the custom hydrator, so read-only on the wire.
            Slug::make('slug')->readOnly(),
            Boolean::make('public'),
            Uuid::make('externalId')->nullable(),

            // Default relation reader: `owner` reads the ManyToOne and `tracks` the
            // ManyToMany straight off the entity associations. `tracks` is a PLAIN
            // join table, so it carries no pivot data (see `orderedTracks` below for
            // the pivot-bearing variant).
            BelongsTo::make('owner')->type('users'),
            BelongsToMany::make('tracks')
                ->type('tracks')
                ->paginate(PagePaginator::make()->withDefaultPerPage(2)),

            // The pivot witness (bundle ADRs 0045/0046). `orderedTracks` is the same
            // far `tracks` type, but backed by the PlaylistEntry association entity
            // that carries the `position`/`addedAt` pivot columns a plain join table
            // cannot. Declaring fields() (real FieldInterface definitions) makes the
            // Doctrine adapter render them as each member's meta.pivot, recognise them
            // as ?filter/?sort keys on this relation's related endpoint, AND upsert
            // the writable ones from the linkage meta on a write — auto-detecting
            // PlaylistEntry (the only to-many on Playlist whose target also has a
            // ManyToOne to Track), so no ->through() override is needed. `position` is
            // writable (set/reordered via meta); `addedAt` is readOnly (server-owned).
            // `extractUsing` maps the parent's entries to their far tracks for the
            // relationship-linkage endpoint.
            BelongsToMany::make('orderedTracks')
                ->type('tracks')
                ->fields(
                    Integer::make('position')->min(1),
                    DateTime::make('addedAt')->readOnly(),
                )
                ->extractUsing(static function (mixed $playlist): array {
                    // The generic engine instantiates the entity without its
                    // constructor (bundle ADR 0029), so on a freshly-created playlist
                    // the `entries` collection is uninitialized rather than empty —
                    // isset() reads it safely (the 201 create response serializes this
                    // relation before any entry exists).
                    if (!$playlist instanceof Playlist || !isset($playlist->entries)) {
                        return [];
                    }

                    $tracks = [];
                    foreach ($playlist->entries as $entry) {
                        if ($entry->track !== null) {
                            $tracks[] = $entry->track;
                        }
                    }

                    return $tracks;
                })
                ->paginate(PagePaginator::make()->withDefaultPerPage(2)),
        ];
    }

    /**
     * A **mutating before-create** hook: stamp an `externalId` for a create that
     * omitted one. A before hook runs with the entity mutable and *before* the
     * persister flush, so the value is durably persisted — a follow-up read returns
     * it. (A real app would mint a deterministic external reference; here it derives
     * a stable token from the minted id so the test can assert it.)
     */
    public function beforeCreate(object $entity, HookContext $context): void
    {
        \assert($entity instanceof Playlist);

        // The generic engine instantiates the entity without its constructor (bundle
        // ADR 0029), so a column the write body omitted is uninitialized rather than
        // its constructor default — isset() reads that safely.
        if (!isset($entity->externalId) || $entity->externalId === '') {
            $entity->externalId = 'ext-' . $entity->id;
        }
    }

    /**
     * A **before-delete guard**: refuse to delete a playlist that still references
     * tracks, aborting with a `409` the route-scoped ExceptionListener renders. A
     * before hook throwing a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
     * aborts the operation — nothing is deleted. An empty playlist deletes normally.
     */
    public function beforeDelete(object $entity, HookContext $context): void
    {
        \assert($entity instanceof Playlist);

        if (!$entity->tracks->isEmpty()) {
            throw HookAbortException::conflict('Cannot delete a playlist that still has tracks.');
        }
    }
}
