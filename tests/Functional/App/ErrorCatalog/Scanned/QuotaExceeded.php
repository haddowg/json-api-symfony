<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ErrorCatalog\Scanned;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * An application's own described error, declared inside the directory the harness
 * kernel names in `json_api.error_codes.paths` — so the compiler pass finds it with no
 * registration of any kind.
 */
final class QuotaExceeded extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The account has used its request quota for this period.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'QUOTA_EXCEEDED',
            status: 429,
            title: 'Quota exceeded',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
