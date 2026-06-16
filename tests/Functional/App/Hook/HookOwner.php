<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

/**
 * The related `hookOwners` resource backing a {@see HookWidget}'s to-one `owner`
 * relationship, so the relationship-mutation lifecycle hooks have a target to set.
 */
final class HookOwner
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
    ) {}
}
