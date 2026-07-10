<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApiBundle\DataProvider\Doctrine\PivotAssociationResolver;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\AlbumEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\AlbumTrackEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistTrackEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\TrackEntity;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The pivot association-entity resolver: a {@see BelongsToMany} pivot relation is
 * backed by a Doctrine association entity (a join modelled to carry pivot columns),
 * resolved either by auto-detection from metadata or by the relation's `->through()`
 * override.
 *
 * - **Auto-detect**: a parent with exactly one to-many association reaching the far
 *   type ({@see PlaylistEntity} → {@see PlaylistTrackEntity} → {@see TrackEntity})
 *   resolves with no `->through()`.
 * - **Through override**: an ambiguous parent ({@see AlbumEntity}, two association
 *   entities reaching the far type) resolves when the relation names the intended
 *   one with `->through(AlbumTrackEntity::class)`.
 * - **Ambiguity / none**: a no-through relation on the ambiguous parent throws a
 *   {@see \LogicException} pointing at `->through()`, as does a non-pivot HasMany
 *   never being treated as pivot-backed.
 */
final class PivotAssociationResolverTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return DoctrineJsonApiTestKernel::class;
    }

    protected function setUp(): void
    {
        // Snapshot the handlers booting the kernel installs, so PHPUnit's strict
        // mode sees a balanced stack at teardown (mirrors JsonApiFunctionalTestCase).
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Pop every handler the kernel boot pushed back to the snapshot, so the
        // global handler stack is balanced (mirrors JsonApiFunctionalTestCase).
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
    public function itAutoDetectsTheAssociationEntityForAnUnambiguousParent(): void
    {
        $resolver = $this->resolver();
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position')->build());

        self::assertTrue($resolver->isPivotRelation($relation));

        $association = $resolver->resolve($relation, new PlaylistEntity(1, 'Set'), TrackEntity::class);

        self::assertSame(PlaylistTrackEntity::class, $association->entityClass);
        self::assertSame('playlist', $association->parentProperty);
        self::assertSame('track', $association->farProperty);
    }

    #[Test]
    public function itHonoursTheThroughOverrideForAnAmbiguousParent(): void
    {
        $resolver = $this->resolver();
        $relation = BelongsToMany::make('tracks', 'tracks')
            ->fields(Integer::make('position')->build())
            ->through(AlbumTrackEntity::class);

        $association = $resolver->resolve($relation, new AlbumEntity(1, 'Album'), TrackEntity::class);

        self::assertSame(AlbumTrackEntity::class, $association->entityClass);
        self::assertSame('album', $association->parentProperty);
        self::assertSame('track', $association->farProperty);
    }

    #[Test]
    public function itThrowsWhenAutoDetectionIsAmbiguousAndNoThroughIsDeclared(): void
    {
        $resolver = $this->resolver();
        $relation = BelongsToMany::make('tracks', 'tracks')->fields(Integer::make('position')->build());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ambiguous');

        $resolver->resolve($relation, new AlbumEntity(1, 'Album'), TrackEntity::class);
    }

    #[Test]
    public function aBelongsToManyWithoutPivotFieldsIsNotPivotBacked(): void
    {
        $resolver = $this->resolver();

        self::assertFalse($resolver->isPivotRelation(BelongsToMany::make('tracks', 'tracks')));
        self::assertFalse($resolver->isPivotRelation(HasMany::make('tracks', 'tracks')));
    }

    private function resolver(): PivotAssociationResolver
    {
        self::bootKernel(['debug' => false]);
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return new PivotAssociationResolver($entityManager);
    }
}
