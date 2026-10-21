<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Unscanned;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * A described error outside every configured scan path and named by no source — the
 * negative case. It stands in for the described error somebody leaves in a test fixture
 * or a scratch directory, which must never reach a published contract.
 */
final class SubscriptionRequired extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('This operation requires an active subscription.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'SUBSCRIPTION_REQUIRED',
            status: 402,
            title: 'Subscription required',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
