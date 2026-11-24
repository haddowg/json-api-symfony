<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

/**
 * {@see IdMatrixDoctrineTestKernel} with posts reading their notes by the bare id field
 * of a MorphTo identified by columns.
 */
final class IdMatrixBareMorphIdKernel extends IdMatrixDoctrineTestKernel
{
    protected function name(): string
    {
        return 'id-matrix-bare-morph-id';
    }

    protected function resources(): array
    {
        return [...\array_values(\array_filter(parent::resources(), static fn(string $resource): bool => $resource !== DoctrineImPostResource::class)), BareMorphIdPostResource::class];
    }
}
