<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationErrors;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * A code only the account's update raises: an account under review refuses edits.
 */
final class AccountLocked extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The account is under review.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ACCOUNT_LOCKED',
            status: 409,
            title: 'Account locked',
            exampleDetail: 'The account is under review.',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
