<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataPersister;

use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataPersister\AbstractDataPersister;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The write-side SPI on-ramp witness (C2): a minimal {@see AbstractDataPersister}
 * subclass that implements only the five write abstracts is constructible, and the
 * inherited {@see AbstractDataPersister::mutateRelationship()} default throws a clear
 * {@see \LogicException} — so a persister that never serves relationship-endpoint
 * writes need not hand-stub it, while one that does is told to override it.
 */
final class AbstractDataPersisterTest extends TestCase
{
    #[Test]
    public function aMinimalSubclassNeedOnlyImplementTheFiveWriteAbstracts(): void
    {
        $persister = $this->minimalPersister();

        self::assertTrue($persister->supports('things'));
        self::assertInstanceOf(\stdClass::class, $persister->instantiate('things'));
    }

    #[Test]
    public function mutateRelationshipThrowsByDefaultDirectingTheAuthorToOverrideIt(): void
    {
        $persister = $this->minimalPersister();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "things" persister does not support relationship mutation; override mutateRelationship()');

        $persister->mutateRelationship(
            'things',
            new \stdClass(),
            $this->createStub(RelationInterface::class),
            new ToOneRelationship(null),
            Mode::Replace,
        );
    }

    private function minimalPersister(): AbstractDataPersister
    {
        return new class extends AbstractDataPersister {
            public function supports(string $type): bool
            {
                return $type === 'things';
            }

            public function instantiate(string $type): object
            {
                return new \stdClass();
            }

            public function create(string $type, object $entity): object
            {
                return $entity;
            }

            public function update(string $type, object $entity): object
            {
                return $entity;
            }

            public function delete(string $type, object $entity): void {}
        };
    }
}
