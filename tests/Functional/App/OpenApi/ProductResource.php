<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The OpenAPI document witness's primary resource: a `products` resource carrying the
 * full breadth the projection exercises —
 *  - a **constrained id** (`matchAs('[0-9]+')`) so the `{id}` path parameter advertises
 *    the anchored `^(?:[0-9]+)$` pattern — the idPattern-derivation witness;
 *  - **explicit OpenAPI tags** (`Catalog`) so its operations group under a config-
 *    defined tag (design §4.7);
 *  - a **backed-enum** attribute (`status`) so the document emits the reusable named
 *    `CatalogStatus` component (§4.8);
 *  - **filters** + **sorts** so the collection operation enumerates `filter[…]` and
 *    `sort` parameters (§4.4);
 *  - a to-one `category` and a to-many `tags` **relation** so the relationship /
 *    related endpoints appear; and
 *  - a `securityRead` + `securityCreate` **expression** so `FetchOne` and `Create`
 *    are reported as secured operations (§4.6/D8) — the expression is `true` so it is
 *    inert at runtime but present for the metadata projection.
 */
#[AsJsonApiResource(
    tags: ['Catalog'],
    securityRead: 'true',
    securityCreate: 'true',
)]
final class ProductResource extends AbstractResource
{
    public static string $type = 'products';

    public function fields(): array
    {
        return [
            // A constrained id (matchAs sets the {id} route requirement) so the served
            // OpenAPI document advertises the anchored pattern on the {id} parameter —
            // the idPattern-derivation witness. The seeded products id is numeric, so
            // a numeric fragment keeps the read routes matchable.
            Id::make()->matchAs('[0-9]+'),
            Str::make('name')->sortable()->required()->minLength(2)->maxLength(120),
            Str::make('status')->sortable()->enum(CatalogStatus::class),
            BelongsTo::make('category', 'categories'),
            HasMany::make('tags', 'categories')->storedAs('tagIds'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('name'),
            Where::make('nameContains', 'name', 'like'),
            Where::make('status'),
            WhereIdIn::make(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortByField::make('name', 'name'),
            SortByField::make('status', 'status'),
        ];
    }
}
