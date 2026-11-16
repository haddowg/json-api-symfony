<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\GateOrder;

/**
 * What ran for `gateWidgets`: each resource hook method, each event an application
 * listener saw, and each action handler, in order.
 */
final class GateLog
{
    /** @var list<string> */
    public static array $entries = [];

    public static function reset(): void
    {
        self::$entries = [];
    }
}
