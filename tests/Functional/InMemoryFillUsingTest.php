<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\FillUsing\FillFile;
use haddowg\JsonApiBundle\Tests\Functional\App\FillUsing\FillUsingFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\FillUsing\FillUsingInMemoryTestKernel;

final class InMemoryFillUsingTest extends FillUsingConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return FillUsingInMemoryTestKernel::class;
    }

    protected function storedTarget(int $file): array
    {
        $stored = $this->file($file);

        return [$stored?->targetType, $stored?->targetId];
    }

    protected function storedWatchers(int $file): array
    {
        return $this->file($file)->watcherIds ?? [];
    }

    protected function storedFileCount(): int
    {
        return \count($this->fixtures()->provider('fill-files')->store()->all());
    }

    private function file(int $id): ?FillFile
    {
        $file = $this->fixtures()->provider('fill-files')->store()->find((string) $id);

        return $file instanceof FillFile ? $file : null;
    }

    private function fixtures(): FillUsingFixtures
    {
        $fixtures = static::getContainer()->get(FillUsingFixtures::class);
        \assert($fixtures instanceof FillUsingFixtures);

        return $fixtures;
    }
}
