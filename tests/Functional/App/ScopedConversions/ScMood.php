<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions;

enum ScMood: string
{
    case Calm = 'calm';
    case Busy = 'busy';
}
