<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\EndsWith;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\StartsWith;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\Tests\Functional\App\Article;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity;
use Symfony\Component\Clock\Clock;

/**
 * The shared `articles` declaration both functional kernels serve: one set of
 * fields, filters and pagination, so the in-memory and Doctrine providers are
 * exercised by **identical** spec assertions and a failure localizes to the
 * provider, not the fixture. The concrete subclasses only choose the data
 * layer ({@see ArticleResource} in-memory,
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineArticleResource}
 * Doctrine-mapped).
 *
 * `title` and `category` are sortable (`category` carries ties, so multi-field
 * sort composition is observable); `body` deliberately is not, so sorting on a
 * declared-but-unsortable field is testable. The filters cover the exact /
 * contains / id-set shapes of the Phase-1 vocabulary.
 *
 * `title` and `category` also carry validation constraints (required + length,
 * and an enum) that are inert on reads but exercised by the write/validation
 * conformance suites — the same declaration drives both halves. `publishedAt`
 * adds an optional date attribute whose closure bound ("not in the future")
 * exercises the date-constraint bridge under a frozen clock, `couponCode`
 * carries a `when()`-declared conditional rule that the bridge executes, and
 * `expiresAt` carries a `CompareField` cross-field rule (must be after
 * `publishedAt`) the bridge evaluates document-first, and `address` is a nested
 * structured attribute (a `Map`) whose constrained children exercise the implicit
 * Valid-cascade — the bridge recurses into them and maps a child violation to
 * `/data/attributes/address/<child>`.
 */
abstract class BaseArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function __construct()
    {
        // The primary collection is client-countable (G21 §6a): `?withCount=_self_`
        // under the Countable profile renders the total; the paginator stays
        // count-free by default, so an unrequested page never counts.
        $this->countable();
    }

    public function fields(): array
    {
        return [
            // Store-provided id: the `articles` entity keys on a database
            // auto-increment, so a create omits `data.id` and the store assigns the
            // next sequential int (rendered as a string on the wire) — the realistic,
            // common pattern. `Id::make()` sets nothing on create (core ADR 0048).
            Id::make(),
            Str::make('title')->sortable()->required()->minLength(3)->maxLength(50),
            Str::make('body'),
            Str::make('category')->sortable()->in(['guide', 'news', 'opinion']),
            // Optional + nullable, with a closure date bound resolved at validation
            // time ("must not be in the future"). Exercises the After/Before bridge
            // and the clock-frozen closure-bound path; inert on reads.
            DateTime::make('publishedAt')->nullable()->before(static fn(): \DateTimeImmutable => Clock::get()->now()),
            // Conditional constraint declared with when(): a coupon code is only
            // length-checked when it looks like a promo code, so a short "FREE"
            // passes while a short "PROMO-X" fails — exercising the When bridge
            // declare→execute path end to end.
            Str::make('couponCode')->nullable()->when(
                static fn(mixed $value): bool => \is_string($value) && \str_starts_with($value, 'PROMO-'),
                static function (Str $field): void {
                    $field->minLength(12);
                },
            ),
            // Cross-field rule: expiresAt must be after publishedAt — exercises the
            // document-level CompareField execution path.
            DateTime::make('expiresAt')->nullable()->compareWith('publishedAt', Comparison::GreaterThan),
            // Structured attribute (Phase 3 S6): a nested `address` object whose two
            // children carry their own constraints. The bridge validates them by
            // recursion (the implicit Valid-cascade), so a too-short `street` or a
            // pattern-violating `postcode` surfaces a 422 at
            // /data/attributes/address/<child>. The whole object round-trips through a
            // single JSON/array `address` member via the Map-level serialize/fill
            // hooks, so the children don't spread across separate columns.
            Map::make('address')->nullable()->fields(
                Str::make('street')->required()->minLength(3),
                Str::make('postcode')->required()->pattern('[0-9]{5}'),
            )->serializeUsing(static function (mixed $model): mixed {
                // `?? null` also tolerates an uninitialised typed property on a freshly
                // instantiated entity (no PHP "accessed before initialization" error).
                $address = match (true) {
                    \is_array($model) => $model['address'] ?? null,
                    $model instanceof Article, $model instanceof ArticleEntity => $model->address ?? null,
                    default => null,
                };

                return $address === [] ? null : $address;
            })->fillUsing(static function (mixed $model, mixed $value): mixed {
                // A JSON object arrives as an array; key it by string for the
                // string-keyed `address` storage member.
                $address = null;
                if (\is_array($value)) {
                    $address = [];
                    foreach ($value as $key => $item) {
                        $address[(string) $key] = $item;
                    }
                }

                if (\is_array($model)) {
                    $model['address'] = $address;
                } elseif ($model instanceof Article || $model instanceof ArticleEntity) {
                    $model->address = $address;
                }

                return $model;
            }),
            // Relationships (Phase 3 foundation): a to-one `author` and a
            // to-many `comments`. Core reads the related objects off the model
            // (`$model->author` / `$model->comments`) and emits resource-identifier
            // linkage via the serializer registered for each related type.
            BelongsTo::make('author', 'authors'),
            // The to-many `comments` relation also declares its OWN scoped filter
            // and sort (core ADR 0051), available ONLY on
            // `GET /articles/{id}/comments` — never the primary `/comments`
            // collection (which exposes just the comment resource's `filter[body]`).
            // `filter[commentBody]` is a contains-match on the comment body and
            // `sort=recent` orders by comment id, so the related endpoint gains a
            // contextual vocabulary the related type does not expose globally. The
            // host merges these with the comment resource's own vocabulary.
            // `withData()` keeps this to-many EAGER (a to-many is lazy by default since
            // the flip to per-type defaults, core ADR 0067): it is the always-emitting
            // regression baseline its data-presence assertions rely on, distinct from
            // the lazy `lazyComments`/`pinnedComments` witnesses below.
            HasMany::make('comments', 'comments')
                ->withData()
                ->withFilters(Where::make('commentBody', 'body', 'like'))
                ->withSorts(SortByField::make('recent', 'id')),
            // Per-relation default paginator (Phase 4 P7): reuses the `comments`
            // property but carries its own PagePaginator, so
            // `GET /articles/1/pagedComments` paginates by `page[number]`/`page[size]`
            // while plain `comments` stays unpaginated. The same related collection,
            // two pagination policies — pinning that pagination is per-relation.
            // Marked countable() (bundle ADR 0052) so its related-collection endpoint
            // computes the total and emits `meta.page.total` + a `last` link, and
            // `?withCount=pagedComments` activates the relationship-object `meta.total`.
            HasMany::make('pagedComments', 'comments')->storedAs('comments')->paginate(PagePaginator::make())->countable(),
            // A unidirectional many-to-many to `authors` (the `editors` property),
            // exercising the Doctrine subquery-scoped related collection: the
            // parent association is owning-side with no single-valued inverse, so
            // the related-collection fetch scopes membership via an IN subquery.
            // Paginated, so the endpoint witnesses page/filter/sort over the
            // subquery. Countable so its endpoint still emits a total over the
            // subquery scope (bundle ADR 0052).
            HasMany::make('editors', 'authors')->storedAs('editors')->paginate(PagePaginator::make())->countable(),
            // A countable, paginated inverse-FK to-many over the UNIQUE `pinnedComments`
            // column (no other relation shares it), so the windowed-include batch (bundle
            // ADR 0065) asserts per-parent order + the REAL total on the inverse-FK shape
            // without the shared-column last-writer-wins boundary the `comments`-backed
            // relations carry.
            HasMany::make('pinnedComments', 'comments')->storedAs('pinnedComments')->paginate(PagePaginator::make())->countable()
                ->withFilters(Where::make('bodyContains', 'body', 'like')),
            // Load-aware relationships under the lazy default (core ADR 0067) so the
            // storage-aware load-state predicate decides whether `data` is emitted.
            // They exercise the predicate on both providers, distinct from the
            // eager `withData()` `comments` baseline above.
            //
            // `lazyAuthor` reuses the `author` property: a to-one. As a `BelongsTo`
            // it is EAGER by default (its id is on the owner), so data always emits —
            // mirroring the Doctrine predicate's "a to-one is always loaded" verdict.
            //
            // `lazyComments` is backed by the SEPARATE `featuredComments`
            // association — one no eager relation reads — so on Doctrine that
            // collection stays an uninitialised PersistentCollection through a
            // plain fetch and the lazy default omits its `data` (links only) unless
            // the relationship is included (include-wins). In-memory has no
            // predicate, so it always emits.
            BelongsTo::make('lazyAuthor', 'authors')->storedAs('author'),
            HasMany::make('lazyComments', 'comments')->storedAs('featuredComments'),
            // Mutability variants (Phase 3 S3): relationship-endpoint mutation
            // guards. `lockedAuthor` reuses the `author` property but forbids
            // replacement (a PATCH to its endpoint is FullReplacementProhibited);
            // `lockedComments` reuses the `comments` property but forbids removal
            // (a DELETE to its endpoint is RemovalProhibited). They read identically
            // to `author`/`comments`, so they don't perturb the read assertions —
            // only the mutation guards exercise them.
            BelongsTo::make('lockedAuthor', 'authors')->storedAs('author')->cannotReplace(),
            HasMany::make('lockedComments', 'comments')->storedAs('comments')->cannotRemove(),
            // A SECOND `withData()` to-many over the SAME `comments` backing column as
            // the `comments` relation above — so the two are CO-WINDOWED on one column,
            // each addressed by its OWN relatedQuery filter and each rendering its own
            // filtered page out-of-band (bundle ADR 0086). It carries a distinct scoped
            // filter key (`flaggedBody`, a contains-match on the comment body) so the
            // two co-windowed relations are filtered to DIFFERENT subsets of the shared
            // membership, witnessing no cross-contamination on either provider.
            HasMany::make('flaggedComments', 'comments')->storedAs('comments')
                ->withData()
                ->withFilters(Where::make('flaggedBody', 'body', 'like')),
            // Read-only relationship (security): reuses the `author` association but
            // is declared readOnly(), so a whole-resource write that names it is
            // silently skipped — a server-assigned association can't be overwritten
            // through the write body (the gate core enforces, reapplied bundle-side).
            BelongsTo::make('readOnlyAuthor', 'authors')->storedAs('author')->readOnly(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('title'),
            Where::make('titleContains', 'title', 'like'),
            // Singular filter (core ADR 0039): a zero-to-one filter on the unique
            // title. Applying it collapses the collection to a single resource (or
            // null), so the same `/articles` endpoint serves a to-one shape.
            Where::make('exactTitle', 'title')->singular(),
            WhereIdIn::make(),
            // Relationship-existence filters (Phase 3 S5). The request value is
            // ignored — presence on the named association decides the match. The
            // to-many `comments` and the to-one `author` both exercise the
            // EXISTS/NOT EXISTS translation on Doctrine and core's non-empty /
            // non-null witness in memory.
            WhereHas::make('hasComments', 'comments'),
            WhereDoesntHave::make('lacksComments', 'comments'),
            WhereHas::make('hasAuthor', 'author'),
            WhereDoesntHave::make('lacksAuthor', 'author'),

            // Convenience filter library (G8b, core ADRs 0075-0077, bundle ADR 0082) —
            // shared across the article kernels so the dual-provider
            // ConvenienceFilterConformanceTestCase and the DoctrineReadQueryBudgetTest
            // both see them. The intent-named string strategies on `title` and the
            // structured numeric Range on the int `id` column.
            //  - Contains: the `like` operator (case-insensitive substring).
            //  - StartsWith / EndsWith: the two NEW prefix/suffix wildcard-LIKE operators
            //    (in-memory stripos===0 / str_ends_with, Doctrine LIKE 'v%' / '%v').
            //  - Range: min/max in one key with numeric coercion — two push-down
            //    `>=`/`<=` andWhere predicates on ONE query (no subquery, no N+1).
            Contains::make('titleHas', 'title'),
            StartsWith::make('titleStarts', 'title'),
            EndsWith::make('titleEnds', 'title'),
            Range::make('idRange', 'id'),
            //  - DateRange: ISO-8601 min/max in one key over the `publishedAt`
            //    date column, coerced to \DateTimeImmutable so the comparison is
            //    temporal. The shape Pattern is lenient on the calendar (it admits
            //    `1997-13-99`), so a calendar-invalid bound is caught as a clean 400
            //    by the filter-value validator (the temporal-validity check) BEFORE
            //    the provider — identical on both providers, never a divergent
            //    lexical match in memory or a strict-driver 500.
            DateRange::make('publishedRange', 'publishedAt'),
        ];
    }

    /**
     * The `articles` collection paginates with the default page strategy and is
     * **count-free by default** (G21): a bare `?page[size]=…` windows without a
     * `COUNT`. The resource is {@see countable()} (in the constructor), so a client
     * may opt into the total per request with `?withCount=_self_` under the
     * negotiated Countable profile — the matrix witness for the primary-collection
     * `_self_` count.
     */
    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return PagePaginator::make();
    }
}
