<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * The create-only witness for operation exposure (ADR 0025): a `signals` resource
 * that declares only {@see Operation::Create}, so the route loader emits exactly
 * `POST /signals` and nothing else — no read/update/delete routes. `name` is
 * required, so a valid POST body must carry it.
 */
#[AsJsonApiResource(operations: [Operation::Create])]
final class SignalResource extends AbstractResource
{
    public static string $type = 'signals';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
        ];
    }
}
