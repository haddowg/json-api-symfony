<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog;

/**
 * The item behind {@see LedgerAccountResource}, held by the harness's in-memory provider.
 */
final class LedgerAccount
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
