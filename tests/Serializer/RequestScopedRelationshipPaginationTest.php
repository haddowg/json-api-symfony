<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApi\Pagination\PageBasedPage;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApi\Serializer\RelationshipPaginationInterface;
use haddowg\JsonApiBundle\Serializer\RequestScopedRelationshipPagination;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The swappable per-request relationship-pagination holder (bundle ADR 0053): it
 * delegates to the windowed backing the handler installs for the profile read in
 * flight, answers `null` with no backing (so core emits no relationship-object
 * pagination links — the profile-not-negotiated default), and clears its backing on
 * {@see RequestScopedRelationshipPagination::reset()} so a profile read's windowed
 * pages never leak into a later render in a long-lived container. Mirrors the
 * slice-1 count holder.
 */
final class RequestScopedRelationshipPaginationTest extends TestCase
{
    #[Test]
    public function itAnswersNullWithNoBackingInstalled(): void
    {
        $holder = new RequestScopedRelationshipPagination();

        self::assertNull($holder->paginateRelationship(new \stdClass(), $this->relation(), $this->request()));
    }

    #[Test]
    public function itDelegatesToTheInstalledBacking(): void
    {
        $holder = new RequestScopedRelationshipPagination();
        $pagination = $this->pagination();

        $holder->set($this->backingReturning($pagination));

        self::assertSame($pagination, $holder->paginateRelationship(new \stdClass(), $this->relation(), $this->request()));
    }

    #[Test]
    public function resetClearsTheBackingSoNoStalePageLeaks(): void
    {
        $holder = new RequestScopedRelationshipPagination();
        $holder->set($this->backingReturning($this->pagination()));

        $holder->reset();

        self::assertNull($holder->paginateRelationship(new \stdClass(), $this->relation(), $this->request()));
    }

    #[Test]
    public function itIsAResettableService(): void
    {
        self::assertInstanceOf(ResetInterface::class, new RequestScopedRelationshipPagination());
    }

    private function relation(): RelationInterface
    {
        return HasMany::make('tracks', 'tracks')->build();
    }

    private function request(): JsonApiRequestInterface
    {
        // A stub (no expectations) — the holder/backing never consult the request.
        return $this->createStub(JsonApiRequestInterface::class);
    }

    private function pagination(): RelationshipPagination
    {
        return new RelationshipPagination(new PageBasedPage([], 0, 1, 15), 'sort=-name');
    }

    private function backingReturning(?RelationshipPagination $pagination): RelationshipPaginationInterface
    {
        return new class ($pagination) implements RelationshipPaginationInterface {
            public function __construct(private readonly ?RelationshipPagination $pagination) {}

            public function paginateRelationship(
                mixed $model,
                RelationInterface $relation,
                JsonApiRequestInterface $request,
            ): ?RelationshipPagination {
                return $this->pagination;
            }
        };
    }
}
