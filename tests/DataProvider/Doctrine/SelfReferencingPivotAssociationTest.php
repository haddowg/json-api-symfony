<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociationResolver;
use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StAmbiguousTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StOwner;
use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StSeat;
use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StTrioSeat;
use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StUuidOwner;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An association entity holding two associations to one class, as a seat linking an owner
 * to another owner does. The relation's column names the owner's collection of seats, and
 * the association that collection is mapped by is the parent side. Without it the sides
 * can't be told apart, which fails the resolution and so the cache warm-up.
 */
final class SelfReferencingPivotAssociationTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return StDoctrineTestKernel::class;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();

        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->errorHandler) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->exceptionHandler) {
                break;
            }
            \restore_exception_handler();
        }
    }

    #[Test]
    public function theCollectionTheRelationIsStoredAsNamesTheParentSide(): void
    {
        $resolver = $this->resolver();

        $peers = $resolver->discover($this->relation('peers', 'seats', through: true), StOwner::class, StOwner::class);
        $followers = $resolver->discover($this->relation('followers', 'peerSeats', through: true), StOwner::class, StOwner::class);

        self::assertSame([StSeat::class, 'owner', 'peer'], [$peers->entityClass, $peers->parentProperty, $peers->farProperty]);
        self::assertSame([StSeat::class, 'peer', 'owner'], [$followers->entityClass, $followers->parentProperty, $followers->farProperty]);
    }

    #[Test]
    public function autoDetectionReadsTheSameSides(): void
    {
        $resolver = $this->resolver();

        $peers = $resolver->discover($this->relation('peers', 'seats', through: false), StOwner::class, StOwner::class);
        $followers = $resolver->discover($this->relation('followers', 'peerSeats', through: false), StOwner::class, StOwner::class);

        self::assertSame([StSeat::class, 'owner', 'peer'], [$peers->entityClass, $peers->parentProperty, $peers->farProperty]);
        self::assertSame([StSeat::class, 'peer', 'owner'], [$followers->entityClass, $followers->parentProperty, $followers->farProperty]);
    }

    #[Test]
    public function aRelationNotStoredAsTheParentsCollectionIsAmbiguous(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The pivot relation "peers" reads ' . StSeat::class . ', which holds several ManyToOne associations to the parent ' . StOwner::class . ' (owner, peer), so its parent side is ambiguous.');

        $this->resolver()->discover($this->relation('peers', null, through: true), StOwner::class, StOwner::class);
    }

    #[Test]
    public function aCollectionMappedByNeitherSideIsAmbiguous(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('so its parent side is ambiguous');

        $this->resolver()->discover($this->relation('peers', 'name', through: true), StOwner::class, StOwner::class);
    }

    #[Test]
    public function severalAssociationsToTheRelatedTypeAreAmbiguous(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The pivot relation "pairs" reads ' . StTrioSeat::class . ', which holds several ManyToOne associations to the related type ' . StUuidOwner::class . ' besides its parent side "owner" (peer, alternate), so its related side is ambiguous.');

        $relation = BelongsToMany::make('pairs', 'st-uuid-owners')->through(StTrioSeat::class)->fields(Integer::make('rank'))->build();
        $this->resolver()->discover($relation, StOwner::class, StUuidOwner::class);
    }

    #[Test]
    public function anAmbiguousRelationFailsTheWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The pivot relation "peers" reads ' . StSeat::class . ', which holds several ManyToOne associations to the parent');

        static::ensureKernelShutdown();
        $kernel = new StAmbiguousTestKernel('test', false);
        $this->removeDir($kernel->getCacheDir());
        static::$class = StAmbiguousTestKernel::class;
        static::bootKernel(['debug' => false]);
    }

    private function relation(string $name, ?string $storedAs, bool $through): BelongsToMany
    {
        $relation = BelongsToMany::make($name, 'st-owners')->fields(Integer::make('rank'));
        if ($storedAs !== null) {
            $relation->storedAs($storedAs);
        }
        if ($through) {
            $relation->through(StSeat::class);
        }

        return $relation->build();
    }

    private function resolver(): PivotAssociationResolver
    {
        self::bootKernel(['debug' => false]);
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return new PivotAssociationResolver($entityManager);
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isDir()) {
                @\rmdir($file->getPathname());
            } else {
                @\unlink($file->getPathname());
            }
        }

        @\rmdir($dir);
    }
}
