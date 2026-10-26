<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

/**
 * The binary-uuid app with a {@see BinaryUuidEncoder} on every type, so wire ids
 * decode to the 16-byte form before the Doctrine layer binds them.
 */
final class EncodedBinaryUuidTestKernel extends BinaryUuidTestKernel
{
    protected const string NAME = 'encoded-binary-uuid';

    protected function encodesIds(): bool
    {
        return true;
    }
}
