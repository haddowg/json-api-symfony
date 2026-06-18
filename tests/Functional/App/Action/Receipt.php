<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

/**
 * A bespoke **response** DTO for the custom-`outputType` action (bundle ADR 0076,
 * design §2/§4): the action returns one of these and it renders through the
 * standalone {@see ReceiptSerializer} as a `receipts` JSON:API document — a response
 * shape decoupled from the mount type.
 */
final class Receipt
{
    public function __construct(
        public string $id,
        public string $appliedName,
    ) {}
}
