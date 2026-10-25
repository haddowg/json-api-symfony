<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeScoping;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The advertiser declaration, shared by the default-server and named-server types.
 * `agencies` is a belongsToMany over a plain many-to-many, `listedAgencies` reads the
 * same association but always emits its linkage data, and `curatedAgencies` is
 * computed by its own closure. `partners` writes the public twin of the same
 * association.
 */
abstract class BaseScopingAdvertiserResource extends AbstractResource
{
    protected const string PREFIX = '';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsToMany::make('agencies', static::PREFIX . 'agencies')->countable(),
            BelongsToMany::make('listedAgencies', static::PREFIX . 'agencies')->storedAs('agencies')->withData(),
            HasMany::make('curatedAgencies', static::PREFIX . 'agencies')->extractUsing(
                static fn(mixed $model): array => $model instanceof ScopingAdvertiser
                    ? \array_values(\array_filter($model->getAgencyList(), static fn(ScopingAgency $agency): bool => !$agency->hidden))
                    : [],
            ),
            BelongsToMany::make('partners', static::PREFIX . 'agencies'),
            // Declares its own read permission, which replaces the agency type's gates
            // but never its scope.
            BelongsToMany::make('openAgencies', static::PREFIX . 'agencies')->storedAs('agencies')->countable()->security(read: false),
            // Computed from the model with no regard for the scope: the agency type's scope
            // still decides which of these rows exist.
            HasOne::make('firstAgency', static::PREFIX . 'agencies')->extractUsing(
                static fn(mixed $model): ?ScopingAgency => $model instanceof ScopingAdvertiser ? ($model->getAgencyList()[0] ?? null) : null,
            ),
            HasMany::make('allAgencies', static::PREFIX . 'agencies')->countable()->extractUsing(
                static fn(mixed $model): array => $model instanceof ScopingAdvertiser ? $model->getAgencyList() : [],
            ),
        ];
    }
}
