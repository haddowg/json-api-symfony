<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

/**
 * The `hookWidgets` resource — a plain resource declaring no hooks, so the
 * lifecycle events exercised against it are observed through an application
 * **event subscriber** ({@see RecordingHookSubscriber}), the cross-cutting
 * mechanism. Its method-path twin is {@see HookableWidgetResource}.
 */
final class HookWidgetResource extends BaseHookWidgetResource
{
    public static string $type = 'hookWidgets';
}
