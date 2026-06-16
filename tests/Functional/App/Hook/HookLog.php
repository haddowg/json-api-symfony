<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

/**
 * A process-static recorder of the lifecycle hook/event firing order, shared
 * between the event-subscriber path ({@see RecordingHookSubscriber}) and the
 * resource-method path ({@see HookableWidgetResource}). A test resets it, issues a
 * request, then asserts {@see entries()} is the expected ordered sequence (e.g.
 * `serving`, `beforeSave`, `beforeCreate`, `afterCreate`, `afterSave`).
 *
 * It also carries the **control** flags a test sets to make a hook throw (to
 * witness an abort) or replace a response (custom-action shaping), so the fixtures
 * stay declarative and a test drives behaviour without bespoke fixture variants.
 */
final class HookLog
{
    /** @var list<string> */
    private static array $entries = [];

    /** The hook name a before-hook should throw at, or null for none. */
    public static ?string $throwAt = null;

    /** The HTTP status the configured throw should carry. */
    public static int $throwStatus = 403;

    /** The hook name an after-hook should replace its response at, or null. */
    public static ?string $replaceAt = null;

    /**
     * When true, the before-update hook records the diff it observed — the
     * `$original` snapshot's `name` and the incoming `$entity`'s `name` — instead
     * of the plain `beforeUpdate` marker, so a test can assert the pre-change
     * snapshot really is the prior state (not the post-hydration entity).
     */
    public static bool $captureUpdateDiff = false;

    public static function reset(): void
    {
        self::$entries = [];
        self::$throwAt = null;
        self::$throwStatus = 403;
        self::$replaceAt = null;
        self::$captureUpdateDiff = false;
    }

    public static function record(string $entry): void
    {
        self::$entries[] = $entry;
    }

    /**
     * Records the before-update entry: the plain `beforeUpdate` marker, or — when
     * {@see $captureUpdateDiff} is set — a `beforeUpdate:original=<…>,entity=<…>`
     * marker capturing the `$original` snapshot's `name` and the incoming
     * `$entity`'s `name`, so a test can assert the snapshot holds the prior value
     * while the entity holds the change.
     */
    public static function recordUpdateDiff(string $originalName, string $entityName): void
    {
        self::$entries[] = self::$captureUpdateDiff
            ? \sprintf('beforeUpdate:original=%s,entity=%s', $originalName, $entityName)
            : 'beforeUpdate';
    }

    /**
     * @return list<string>
     */
    public static function entries(): array
    {
        return self::$entries;
    }

    /**
     * Throws the configured abort exception when `$hook` matches {@see $throwAt}.
     *
     * @throws ThrowingHookException
     */
    public static function maybeThrow(string $hook): void
    {
        if (self::$throwAt === $hook) {
            throw new ThrowingHookException(self::$throwStatus);
        }
    }

    /**
     * Whether an after-hook named `$hook` should replace its response.
     */
    public static function shouldReplace(string $hook): bool
    {
        return self::$replaceAt === $hook;
    }
}
