<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * The read-only witness for operation exposure (ADR 0025): a `ledgers` resource
 * that declares only the two read operations, so the route loader emits exactly
 * `GET /ledgers` and `GET /ledgers/{id}` and nothing else — no create/update/delete
 * routes even though it is a full resource.
 */
#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne])]
final class LedgerResource extends AbstractResource
{
    public static string $type = 'ledgers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
