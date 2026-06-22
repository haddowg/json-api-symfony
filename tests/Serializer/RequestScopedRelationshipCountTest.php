<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipCountInterface;
use haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipCount;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The swappable per-request count holder (bundle ADR 0052): it delegates to the
 * backing the handler installs for the read in flight, answers `null` with no
 * backing, and — crucially for a long-lived container (a worker reusing the kernel)
 * — clears its backing on {@see RequestScopedRelationshipCount::reset()} so a
 * `?withCount` read's counts never leak into a later write/linkage render. It is a
 * {@see ResetInterface}, so the container auto-tags it `kernel.reset` and runs that
 * reset between messages.
 */
final class RequestScopedRelationshipCountTest extends TestCase
{
    #[Test]
    public function itAnswersNullWithNoBackingInstalled(): void
    {
        $holder = new RequestScopedRelationshipCount();

        self::assertNull($holder->countRelationship(new \stdClass(), $this->relation()));
    }

    #[Test]
    public function itDelegatesToTheInstalledBacking(): void
    {
        $holder = new RequestScopedRelationshipCount();
        $model = new \stdClass();
        $relation = $this->relation();

        $holder->set($this->backingReturning(7));

        self::assertSame(7, $holder->countRelationship($model, $relation));
    }

    #[Test]
    public function resetClearsTheBackingSoNoStaleCountLeaks(): void
    {
        $holder = new RequestScopedRelationshipCount();
        $holder->set($this->backingReturning(7));

        // The reset the container runs between worker messages.
        $holder->reset();

        // With the backing cleared the holder answers null again — the prior request's
        // count cannot leak into a later render that does not re-set the holder.
        self::assertNull($holder->countRelationship(new \stdClass(), $this->relation()));
    }

    #[Test]
    public function itIsAResettableService(): void
    {
        self::assertInstanceOf(ResetInterface::class, new RequestScopedRelationshipCount());
    }

    private function relation(): RelationInterface
    {
        return HasMany::make('tracks', 'tracks');
    }

    private function backingReturning(?int $count): RelationshipCountInterface
    {
        return new class ($count) implements RelationshipCountInterface {
            public function __construct(private readonly ?int $count) {}

            public function countRelationship(mixed $model, RelationInterface $relation): ?int
            {
                return $this->count;
            }
        };
    }
}
