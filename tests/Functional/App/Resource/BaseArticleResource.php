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
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
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

    public function fields(): array
    {
        return [
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
            Map::make('address')->fields(
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
            BelongsTo::make('author')->type('authors'),
            HasMany::make('comments')->type('comments'),
            // Per-relation default paginator (Phase 4 P7): reuses the `comments`
            // property but carries its own PagePaginator, so
            // `GET /articles/1/pagedComments` paginates by `page[number]`/`page[size]`
            // while plain `comments` stays unpaginated. The same related collection,
            // two pagination policies — pinning that pagination is per-relation.
            HasMany::make('pagedComments')->type('comments')->storedAs('comments')->paginate(PagePaginator::make()),
            // A unidirectional many-to-many to `authors` (the `editors` property),
            // exercising the Doctrine subquery-scoped related collection: the
            // parent association is owning-side with no single-valued inverse, so
            // the related-collection fetch scopes membership via an IN subquery.
            // Paginated, so the endpoint witnesses page/filter/sort over the
            // subquery.
            HasMany::make('editors')->type('authors')->storedAs('editors')->paginate(PagePaginator::make()),
            // Load-aware relationships opting into linkageOnlyWhenLoaded() so the
            // storage-aware load-state predicate decides whether `data` is
            // emitted. They exercise the predicate on both providers without
            // changing the always-emitting `author`/`comments` above (the
            // regression baseline).
            //
            // `lazyAuthor` reuses the `author` property: a to-one, which the
            // Doctrine predicate always reports loaded (a lazy proxy carries its
            // id), so data always emits.
            //
            // `lazyComments` is backed by the SEPARATE `featuredComments`
            // association — one no eager relation reads — so on Doctrine that
            // collection stays an uninitialised PersistentCollection through a
            // plain fetch and the predicate omits its `data` (links only) unless
            // the relationship is included (include-wins). In-memory has no
            // predicate, so it always emits.
            BelongsTo::make('lazyAuthor')->type('authors')->storedAs('author')->linkageOnlyWhenLoaded(),
            HasMany::make('lazyComments')->type('comments')->storedAs('featuredComments')->linkageOnlyWhenLoaded(),
            // Mutability variants (Phase 3 S3): relationship-endpoint mutation
            // guards. `lockedAuthor` reuses the `author` property but forbids
            // replacement (a PATCH to its endpoint is FullReplacementProhibited);
            // `lockedComments` reuses the `comments` property but forbids removal
            // (a DELETE to its endpoint is RemovalProhibited). They read identically
            // to `author`/`comments`, so they don't perturb the read assertions —
            // only the mutation guards exercise them.
            BelongsTo::make('lockedAuthor')->type('authors')->storedAs('author')->cannotReplace(),
            HasMany::make('lockedComments')->type('comments')->storedAs('comments')->cannotRemove(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('title'),
            Where::make('titleContains', 'title', 'like'),
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
        ];
    }

    public function pagination(): ?PaginatorInterface
    {
        return PagePaginator::make();
    }
}
