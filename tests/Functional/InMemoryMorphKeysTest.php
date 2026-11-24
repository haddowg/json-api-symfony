<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\MorphKeysInMemoryKernel;

/**
 * {@see MorphKeysConformanceTestCase} against the in-memory provider, whose filters run
 * through core's reference `ArrayFilterHandler`.
 */
final class InMemoryMorphKeysTest extends MorphKeysConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return MorphKeysInMemoryKernel::class;
    }
}
