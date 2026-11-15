<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

/**
 * What the test hooks and actions do on the next request: which event writes a note on the
 * side, which one throws, which ones open and commit a transaction of the hook's own, and
 * which register a callback to run after the write commits. {@see $log} records the events
 * and callbacks in the order they ran.
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

    /** Whether the `txWidgets` persister throws once its write has flushed. */
    public static bool $persisterThrows = false;

    /** The user the Doctrine kernel's blame listener stamps on what it persists. */
    public static ?TxUser $user = null;

    /** The connection's transaction nesting level the last side write ran at, on Doctrine. */
    public static ?int $levelSeen = null;

    /** @var class-string|null the event whose subscriber registers an after-commit callback */
    public static ?string $afterCommitOn = null;

    /** Whether a callback that throws is registered ahead of each after-commit callback. */
    public static bool $throwingCallback = false;

    /** Whether the actions' handlers register an after-commit callback. */
    public static bool $actionAfterCommit = false;

    /** Whether the stamp action returns an error response instead of throwing. */
    public static bool $actionReturnsError = false;

    /** @var list<string> the `txWidgets` events and the after-commit callbacks, in the order they ran */
    public static array $log = [];

    /** @var list<int|null> the connection's nesting level each after-commit callback ran at, on Doctrine */
    public static array $callbackLevels = [];

    /** @var list<string> the messages logged at error level */
    public static array $errors = [];

    public static function reset(): void
    {
        self::$sideWriteOn = null;
        self::$throwOn = null;
        self::$throwForName = null;
        self::$nestOn = null;
        self::$unnestOn = null;
        self::$accept = false;
        self::$actionThrows = false;
        self::$persisterThrows = false;
        self::$user = null;
        self::$levelSeen = null;
        self::$afterCommitOn = null;
        self::$throwingCallback = false;
        self::$actionAfterCommit = false;
        self::$actionReturnsError = false;
        self::$log = [];
        self::$callbackLevels = [];
        self::$errors = [];
    }
}
