<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Scanned;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorScope;

/**
 * A described error the scan finds whose own descriptor scopes it to one operation:
 * only an update of a ledger account can meet a frozen account, so only that operation
 * offers the code.
 */
final class AccountFrozen extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The account is frozen.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ACCOUNT_FROZEN',
            status: 409,
            title: 'Account frozen',
            scope: ErrorScope::on('ledgerAccounts', 'update'),
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
