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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An association entity holding two associations to one class, as a seat linking an owner
 * to another owner does. `through(parent:, related:)` names its sides, each checked to be
 * a ManyToOne to the right class; otherwise the relation's column may name the owner's
 * collection of seats, and the association that collection is mapped by is the parent
 * side. Without either the sides can't be told apart, which fails the resolution and so
 * the cache warm-up, naming the candidates and the through() that settles it.
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
        $this->expectExceptionMessage('The pivot relation "peers" reads ' . StSeat::class . ', which holds several ManyToOne associations that could be its parent side, to ' . StOwner::class . ": owner, peer. Name the one that points back at the parent, such as ->through(StSeat::class, parent: 'owner').");

        $this->resolver()->discover($this->relation('peers', null, through: true), StOwner::class, StOwner::class);
    }

    #[Test]
    public function aCollectionMappedByNeitherSideIsAmbiguous(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('could be its parent side');

        $this->resolver()->discover($this->relation('peers', 'name', through: true), StOwner::class, StOwner::class);
    }

    #[Test]
    public function severalAssociationsToTheRelatedTypeAreAmbiguous(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The pivot relation "pairs" reads ' . StTrioSeat::class . ', which holds several ManyToOne associations that could be its related side, to ' . StUuidOwner::class . ": peer, alternate. Name the one that points at the member, such as ->through(StTrioSeat::class, related: 'peer').");

        $relation = BelongsToMany::make('pairs', 'st-uuid-owners')->through(StTrioSeat::class)->fields(Integer::make('rank'))->build();
        $this->resolver()->discover($relation, StOwner::class, StUuidOwner::class);
    }

    #[Test]
    public function theSidesThroughNamesAreTaken(): void
    {
        $resolver = $this->resolver();

        $followers = $resolver->discover(BelongsToMany::make('followers', 'st-owners')->through(StSeat::class, parent: 'peer', related: 'owner')->fields(Integer::make('rank'))->build(), StOwner::class, StOwner::class);
        $parentOnly = $resolver->discover(BelongsToMany::make('followers', 'st-owners')->through(StSeat::class, parent: 'peer')->fields(Integer::make('rank'))->build(), StOwner::class, StOwner::class);
        $relatedOnly = $resolver->discover(BelongsToMany::make('peers', 'st-owners')->through(StSeat::class, related: 'peer')->fields(Integer::make('rank'))->build(), StOwner::class, StOwner::class);
        $pairs = $resolver->discover(BelongsToMany::make('pairs', 'st-uuid-owners')->through(StTrioSeat::class, related: 'alternate')->fields(Integer::make('rank'))->build(), StOwner::class, StUuidOwner::class);

        self::assertSame(['peer', 'owner'], [$followers->parentProperty, $followers->farProperty]);
        self::assertSame(['peer', 'owner'], [$parentOnly->parentProperty, $parentOnly->farProperty]);
        self::assertSame(['owner', 'peer'], [$relatedOnly->parentProperty, $relatedOnly->farProperty]);
        self::assertSame([StTrioSeat::class, 'owner', 'alternate'], [$pairs->entityClass, $pairs->parentProperty, $pairs->farProperty]);
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function badSides(): iterable
    {
        yield 'a parent side that is no association' => ['rank', null, "The pivot relation \"peers\" declares through(StSeat::class, parent: 'rank'), but " . StSeat::class . ' has no ManyToOne "rank" to ' . StOwner::class . '. Its ManyToOne associations to ' . StOwner::class . ' are: owner, peer.'];
        yield 'a related side that is no association' => [null, 'seat', "The pivot relation \"peers\" declares through(StSeat::class, related: 'seat'), but " . StSeat::class . ' has no ManyToOne "seat" to ' . StOwner::class . '. Its ManyToOne associations to ' . StOwner::class . ' are: owner, peer.'];
    }

    #[Test]
    #[DataProvider('badSides')]
    public function aSideThatIsNoManyToOneToItsClassIsRefused(?string $parent, ?string $related, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        $relation = BelongsToMany::make('peers', 'st-owners')->through(StSeat::class, parent: $parent, related: $related)->fields(Integer::make('rank'))->build();
        $this->resolver()->discover($relation, StOwner::class, StOwner::class);
    }

    #[Test]
    public function aSideToTheWrongClassIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("The pivot relation \"pairs\" declares through(StTrioSeat::class, related: 'owner'), but " . StTrioSeat::class . ' has no ManyToOne "owner" to ' . StUuidOwner::class . '. Its ManyToOne associations to ' . StUuidOwner::class . ' are: peer, alternate.');

        $relation = BelongsToMany::make('pairs', 'st-uuid-owners')->through(StTrioSeat::class, related: 'owner')->fields(Integer::make('rank'))->build();
        $this->resolver()->discover($relation, StOwner::class, StUuidOwner::class);
    }

    #[Test]
    public function anAmbiguousRelationFailsTheWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The pivot relation "peers" reads ' . StSeat::class . ', which holds several ManyToOne associations that could be its parent side');

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
