<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid\BinaryUuidTestKernel;
use PHPUnit\Framework\Attributes\Group;

/**
 * {@see BinaryUuidConformanceTestCase} with no id encoder: the Doctrine layer
 * converts the wire id through the `uuid` type itself.
 */
#[Group('spec:crud')]
final class DoctrineBinaryUuidTest extends BinaryUuidConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return BinaryUuidTestKernel::class;
    }

}
