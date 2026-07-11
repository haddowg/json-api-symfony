<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToBuilder;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasManyBuilder;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdInBuilder;
use haddowg\JsonApi\Resource\Filter\WhereThrough;

/**
 * The shared `articles` declaration for the filter-value-constraint conformance
 * suite: the {@see BaseArticleResource} vocabulary plus filters that declare
 * **value constraints** —
 *  - `id` ({@see WhereIdIn}) constrained `->integer()`, so each member of
 *    `filter[id]=…` must be an integer (a mistyped `filter[id]=banana` is a clean
 *    `400` with `source.parameter`, not the provider's silent non-match — nor, on a
 *    strict driver, a PDO `500`);
 *  - `numericId` (a single-value {@see Where} on the `id` column) constrained
 *    `->integer()`, witnessing the single-scalar path;
 *  - `byCategory` ({@see Where} on `category`) constrained `->pattern(...)` to the
 *    known categories, witnessing a non-numeric constraint; and
 *  - a `category` filter with **no** constraints and a `->default('guide')`,
 *    witnessing that an unconstrained filter is unaffected and an author-set
 *    default value is never validated.
 *
 * The to-many `comments` relation also re-declares its scoped `commentId` filter
 * constrained `->integer()`, so the related-collection endpoint
 * (`GET /articles/{id}/comments`) validates a relation-scoped filter value the
 * same way (ADR 0048).
 *
 * Not `final` so the Doctrine variant
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineConstrainedFilterArticleResource})
 * inherits the same declarations — both providers run identical assertions
 * ({@see \haddowg\JsonApiBundle\Tests\Functional\FilterValueConstraintConformanceTestCase}).
 */
class ConstrainedFilterArticleResource extends BaseArticleResource
{
    public function fields(): array
    {
        $fields = [];
        foreach (parent::fields() as $field) {
            // Re-declare the `comments` to-many with a relation-scoped, integer-
            // constrained `commentId` filter so the related-collection endpoint has a
            // constrained relation filter to validate.
            if ($field instanceof HasManyBuilder && $field->name() === 'comments') {
                $field = HasMany::make('comments', 'comments')
                    ->withFilters(Where::make('commentId', 'id')->integer());
            }

            // Re-declare the `author` to-one with a relation-scoped, integer-constrained
            // `authorId` filter, so the to-one related/relationship endpoints AND the
            // include/linkage path have a constrained to-one filter to validate — the
            // to-one twin of the `comments` constrained filter (bundle ADR 0068 follow-up
            // #2). A mistyped value (via `?filter[authorId]`, `relatedQuery[author][filter]`,
            // or the include path) is the endpoint's same 400.
            if ($field instanceof BelongsToBuilder && $field->name() === 'author') {
                $field = BelongsTo::make('author', 'authors')
                    ->withFilters(Where::make('authorId', 'id')->integer());
            }

            $fields[] = $field;
        }

        return $fields;
    }

    public function filters(): array
    {
        $filters = [];
        foreach (parent::filters() as $filter) {
            // Replace the inherited unconstrained id-set filter with an integer-
            // constrained one (same `id` key), so each member of filter[id]=… must be
            // an integer and a mistyped filter[id]=banana is a clean 400 — no
            // duplicate `id` key.
            if ($filter instanceof WhereIdInBuilder) {
                $filter = WhereIdIn::make()->integer();
            }

            $filters[] = $filter;
        }

        return [
            ...$filters,
            // A single-value integer filter on the id column (the single-scalar path).
            Where::make('numericId', 'id')->integer(),
            // A non-numeric constraint: the value must be one of the known categories.
            Where::make('byCategory', 'category')->pattern('^(?:guide|news|opinion)$'),

            // --- WhereThrough traversal filters (G8, core ADR 0063) ---
            // A correlated EXISTS-ANY semi-join over a dotted relationship path,
            // executed identically on both providers (in-memory walk via Accessor /
            // Doctrine EXISTS subquery). The same declaration drives the dual-provider
            // WhereThroughConformanceTestCase.
            //
            // Single-hop to-one → attribute: `filter[author.name]` keeps an article
            // whose author's name matches (path-as-key default).
            WhereThrough::make('author.name'),
            // Single-hop to-many EXISTS-ANY with the fluent `like` operator: keep an
            // article that has SOME comment whose body contains the value.
            WhereThrough::make('comments.body')->operator('like'),
            // Named-key override, path distinct from the key: `filter[topAuthor]`
            // traverses `author.name` (witnessing make(key, path)).
            WhereThrough::make('topAuthor', 'author.name'),
            // Multi-hop chain (to-many → to-one → attribute): keep an article that has
            // SOME comment whose owning article's title matches — the self-referential
            // traversal exercising chained joins inside one EXISTS subquery.
            WhereThrough::make('commentArticleTitle', 'comments.article.title'),
            // Many-to-many → attribute (the owning-side `editors`): keep an article one
            // of whose editors' names matches — a second to-many arity over a
            // many-to-many hop.
            WhereThrough::make('editorName', 'editors.name'),
            // A non-eq operator on a numeric leaf: keep an article whose author's id is
            // >= the value (witnessing the fluent operator over a comparison).
            WhereThrough::make('authorIdAtLeast', 'author.id')->operator('>='),
            // A VALUE-CONSTRAINED traversal filter: the leaf value must be an integer,
            // so a mistyped `filter[authorNum]=banana` is a clean 400 (FILTER_VALUE_INVALID,
            // source.parameter) BEFORE the EXISTS subquery runs — the WhereThrough twin of
            // the constrained `numericId` (the single-scalar value path; WhereThrough has
            // no delimiter).
            WhereThrough::make('authorNum', 'author.id')->integer(),
            // A CONSTRAINED filter whose author-set default would itself VIOLATE the
            // constraint: a `like` contains-match on `title`, constrained to a
            // non-empty value, defaulting to `''`. The default `title LIKE '%%'`
            // matches every row, so it does not narrow the universal result set — but
            // `''` fails the `^.+$` pattern. A request that omits `filter[anyTitle]`
            // therefore still returns 200 (the default is trusted and never
            // validated); only a client-supplied empty value would be a 400.
            Where::make('anyTitle', 'title', 'like')->pattern('^.+$')->default(''),

            // --- Server-composed filter groups + ->fixed() (#24b, core ADR 0129) ---
            // The same declarations drive the dual-provider FilterGroupConformanceTestCase.
            //
            // WhereAny fan-out search: one value fanned across two columns —
            // filter[search]=nd matches title LIKE '%nd%' OR body LIKE '%nd%'.
            WhereAny::make('search', Contains::make('title'), Contains::make('body')),
            // WhereAll canned toggle (all children fixed): filter[hotNews] present
            // applies category = 'news' AND body LIKE '%workers%'; the request value
            // is ignored (a presence trigger).
            WhereAll::make(
                'hotNews',
                Where::make('category')->fixed('news'),
                Contains::make('body')->fixed('workers'),
            ),
            // Nested (A AND (B OR C)): title LIKE '%value%' AND (category = 'guide' OR
            // body LIKE '%workers%'). The value fans to the title child; the fixed
            // children in the inner OR ignore it.
            WhereAll::make(
                'scoped',
                Contains::make('title'),
                WhereAny::make(
                    'inner',
                    Where::make('category')->fixed('guide'),
                    Contains::make('body')->fixed('workers'),
                ),
            ),
            // Standalone ->fixed(): filter[onlyGuides]=<anything> pins category = 'guide'
            // regardless of the sent value; omitting it does not apply it.
            Where::make('onlyGuides', 'category')->fixed('guide'),
        ];
    }
}
