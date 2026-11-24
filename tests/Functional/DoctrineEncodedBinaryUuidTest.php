<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid\EncodedBinaryUuidTestKernel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * {@see BinaryUuidConformanceTestCase} with an id encoder that decodes the wire id
 * to its 16-byte form, which the Doctrine layer then binds through the `uuid` type.
 */
#[Group('spec:crud')]
final class DoctrineEncodedBinaryUuidTest extends BinaryUuidConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return EncodedBinaryUuidTestKernel::class;
    }

    /**
     * Odd notes hold the 16-byte storage key, even ones the canonical wire id.
     */
    protected function storedMorphKey(int $note, string $wireId): string
    {
        return $note % 2 === 1 ? Uuid::fromString($wireId)->toBinary() : $wireId;
    }
}
