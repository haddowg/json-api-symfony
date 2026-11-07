<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationErrors;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * A code only the `freeze` action raises.
 */
final class OverdraftRefused extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('An overdrawn account cannot be frozen.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'OVERDRAFT_REFUSED',
            status: 402,
            title: 'Overdraft refused',
            exampleDetail: 'An overdrawn account cannot be frozen.',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
