<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Composite;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Context;

/**
 * An application-defined constraint outside core's vocabulary: a three-letter
 * upper-case stop code, enforced through {@see StopCodeTranslator}.
 */
final readonly class StopCode implements ConstraintInterface
{
    public function context(): Context
    {
        return new Context();
    }
}
