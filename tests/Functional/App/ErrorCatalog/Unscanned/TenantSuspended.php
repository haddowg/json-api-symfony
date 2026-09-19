<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Unscanned;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * A described error outside every configured scan path that the harness kernel names
 * explicitly, through a tagged {@see \haddowg\JsonApi\Exception\ClassListErrorSource} —
 * the escape hatch. It sits beside {@see SubscriptionRequired} on purpose: the two are
 * equally unreachable by the scan, and only the registration tells them apart.
 */
final class TenantSuspended extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The tenant is suspended.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'TENANT_SUSPENDED',
            status: 423,
            title: 'Tenant suspended',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
