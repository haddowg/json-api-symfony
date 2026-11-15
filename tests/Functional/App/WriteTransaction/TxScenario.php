<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

/**
 * What the test hooks and actions do on the next request: which event writes a note on the
 * side, which one throws, and which ones open and commit a transaction of the hook's own.
 */
final class TxScenario
{
    /** @var class-string|null */
    public static ?string $sideWriteOn = null;

    /** @var class-string|null */
    public static ?string $throwOn = null;

    /** The widget name a throw is limited to, or null for any widget. */
    public static ?string $throwForName = null;

    /** @var class-string|null */
    public static ?string $nestOn = null;

    /** @var class-string|null */
    public static ?string $unnestOn = null;

    /** Whether the `txWidgets` persister accepts a create or update for asynchronous processing. */
    public static bool $accept = false;

    /** Whether the actions' handlers throw once they have written. */
    public static bool $actionThrows = false;

    /** The connection's transaction nesting level the last side write ran at, on Doctrine. */
    public static ?int $levelSeen = null;

    public static function reset(): void
    {
        self::$sideWriteOn = null;
        self::$throwOn = null;
        self::$throwForName = null;
        self::$nestOn = null;
        self::$unnestOn = null;
        self::$accept = false;
        self::$actionThrows = false;
        self::$levelSeen = null;
    }
}
