<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Unscanned;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * A described error with no scope of its own, standing in for one the application does
 * not own. The harness kernel catalogues it through a
 * {@see \haddowg\JsonApi\Exception\ScopedErrorSource} service that scopes it to the
 * delete of a ledger account.
 */
final class OverdraftRefused extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The account cannot be closed while overdrawn.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'OVERDRAFT_REFUSED',
            status: 402,
            title: 'Overdraft refused',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
