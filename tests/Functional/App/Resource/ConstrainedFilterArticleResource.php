<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;

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
            if ($field instanceof HasMany && $field->name() === 'comments') {
                $field = HasMany::make('comments')->type('comments')
                    ->withFilters(Where::make('commentId', 'id')->integer());
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
            if ($filter instanceof WhereIdIn) {
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
            // A CONSTRAINED filter whose author-set default would itself VIOLATE the
            // constraint: a `like` contains-match on `title`, constrained to a
            // non-empty value, defaulting to `''`. The default `title LIKE '%%'`
            // matches every row, so it does not narrow the universal result set — but
            // `''` fails the `^.+$` pattern. A request that omits `filter[anyTitle]`
            // therefore still returns 200 (the default is trusted and never
            // validated); only a client-supplied empty value would be a 400.
            Where::make('anyTitle', 'title', 'like')->pattern('^.+$')->default(''),
        ];
    }
}
