<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

use haddowg\JsonApi\OpenApi\Metadata\DescribesRawBody;

final class WidgetExportSummary implements DescribesRawBody
{
    public static function jsonSchema(): array
    {
        return ['type' => 'object', 'properties' => ['count' => ['type' => 'integer']], 'required' => ['count']];
    }
}
