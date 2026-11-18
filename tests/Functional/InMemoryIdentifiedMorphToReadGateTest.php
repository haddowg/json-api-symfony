<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate\IdentifiedMorphGateFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate\IdentifiedMorphGateInMemoryTestKernel;

/**
 * {@see IdentifiedMorphToReadGateConformanceTestCase} against the in-memory provider.
 */
final class InMemoryIdentifiedMorphToReadGateTest extends IdentifiedMorphToReadGateConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IdentifiedMorphGateInMemoryTestKernel::class;
    }

    protected function setUp(): void
    {
        IdentifiedMorphGateFixtures::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        IdentifiedMorphGateFixtures::reset();
    }
}
