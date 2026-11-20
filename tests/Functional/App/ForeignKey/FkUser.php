<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

/**
 * A user served by {@see FkUserProvider}, not by Doctrine. Its foreign-key relations hold
 * nothing on the model; {@see $pinned} and {@see $favourite} are the items its provider
 * loads onto it, read off the model. {@see $lockedItems} only holds the items an action's
 * input names; reads of that relation go through the foreign key.
 */
final class FkUser
{
    /**
     * @var list<object>
     */
    public array $lockedItems = [];

    /**
     * @param list<FkItem> $pinned
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public array $pinned = [],
        public ?FkItem $favourite = null,
    ) {}
}
