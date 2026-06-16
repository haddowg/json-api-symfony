<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

/**
 * A plain domain model for the lifecycle-hooks suite: a `hookWidgets` resource
 * with a writable `name`, a `stamp` field a before-create hook sets (to witness a
 * before-hook mutation being persisted), and a to-one `owner` relationship the
 * relationship-mutation hooks exercise.
 */
final class HookWidget
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public string $stamp = '',
        public ?HookOwner $owner = null,
    ) {}
}
