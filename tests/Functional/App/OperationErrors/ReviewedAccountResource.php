<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationErrors;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A type whose update alone raises {@see AccountLocked}.
 */
#[AsJsonApiResource(operationErrors: ['update' => [AccountLocked::class]])]
final class ReviewedAccountResource extends AbstractResource
{
    public static string $type = 'accounts';

    public function fields(): array
    {
        return [
            Id::make()->example('a1'),
            Str::make('name')->example('Operating'),
        ];
    }
}
