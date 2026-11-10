<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\ThroughInFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\ThroughInInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * {@see WhereThroughInConformanceTestCase} against the in-memory provider, whose
 * filters run through core's reference `ArrayFilterHandler`.
 */
final class InMemoryWhereThroughInTest extends WhereThroughInConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ThroughInInMemoryTestKernel::class;
    }

    protected function appliedDirectly(WhereThroughIn $filter, mixed $value): array
    {
        return $this->leadIdsOf((new ArrayFilterHandler())->apply($filter, ThroughInFixtures::graph()['leads'], $value));
    }

    #[Test]
    public function aPathEndingOnAPolymorphicRelationFailsTheWarmUp(): void
    {
        static::ensureKernelShutdown();
        ThroughInInMemoryTestKernel::$polymorphicLeaf = true;

        try {
            $kernel = new ThroughInInMemoryTestKernel('test', false);
            (new \Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('The JSON:API resource for type "wtiMentions" on server "default" declares a filter that cannot run');
            $this->expectExceptionMessage('ends its path "subject" on the polymorphic relation "subject"');
            KernelTestCase::bootKernel(['debug' => false]);
        } finally {
            ThroughInInMemoryTestKernel::$polymorphicLeaf = false;
            static::ensureKernelShutdown();
        }
    }
}
