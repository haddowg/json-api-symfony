<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NestedFieldsets;

/**
 * The kinds of a panel block, the discriminator of the `blocks` union list.
 */
enum BlockType: string
{
    case Text = 'text';
    case Image = 'image';
    case Divider = 'divider';
}
