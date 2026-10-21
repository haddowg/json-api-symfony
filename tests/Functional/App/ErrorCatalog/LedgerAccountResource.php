<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The one type the error-catalogue harness server exposes. The document under test is
 * about `components.schemas`, so this only has to give the projector a type to describe.
 */
final class LedgerAccountResource extends AbstractResource
{
    public static string $type = 'ledgerAccounts';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
