<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The store-provided-id witness (bundle ADR 0039): a plain `Id::make()` over an entity
 * whose id the database assigns ({@see CounterEntity}'s auto-increment integer). A
 * create sets nothing on the id — the persister flushes and the DB assigns it, and the
 * `201` body + Location read the assigned id back. The `marker` relation drives the
 * linkage-format witness: a write linking a `markers` id has it validated against the
 * `markers` `uuid()` format.
 */
#[AsJsonApiResource(entity: CounterEntity::class)]
final class CounterResource extends AbstractResource
{
    public static string $type = 'counters';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label')->required(),
            BelongsTo::make('marker')->type('markers')->nullable(),
        ];
    }
}
