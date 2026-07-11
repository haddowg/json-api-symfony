<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApi\Pagination\PageBasedPage;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\RelationshipPagination;
use haddowg\JsonApiBundle\Serializer\WindowedRelationshipPagination;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The per-page windowed-pagination backing (bundle ADR 0053): a render-time lookup
 * keyed by the parent's object identity then relation name, exactly as the slice-1
 * {@see \haddowg\JsonApiBundle\Serializer\BatchedRelationshipCount} is. A parent or
 * relation absent from the pre-computed map returns `null` (no pagination links), and
 * a non-object model returns `null`.
 */
final class WindowedRelationshipPaginationTest extends TestCase
{
    #[Test]
    public function itReturnsThePageForAKnownParentAndRelation(): void
    {
        $parent = new \stdClass();
        $pagination = $this->pagination();

        $backing = new WindowedRelationshipPagination([
            \spl_object_id($parent) => ['tracks' => $pagination],
        ]);

        self::assertSame($pagination, $backing->paginateRelationship($parent, $this->relation('tracks'), $this->request()));
    }

    #[Test]
    public function itReturnsNullForAnUnmappedRelation(): void
    {
        $parent = new \stdClass();
        $backing = new WindowedRelationshipPagination([
            \spl_object_id($parent) => ['tracks' => $this->pagination()],
        ]);

        self::assertNull($backing->paginateRelationship($parent, $this->relation('comments'), $this->request()));
    }

    #[Test]
    public function itReturnsNullForAnUnmappedParent(): void
    {
        $backing = new WindowedRelationshipPagination([]);

        self::assertNull($backing->paginateRelationship(new \stdClass(), $this->relation('tracks'), $this->request()));
    }

    #[Test]
    public function itReturnsNullForANonObjectModel(): void
    {
        $backing = new WindowedRelationshipPagination([]);

        self::assertNull($backing->paginateRelationship('not-an-object', $this->relation('tracks'), $this->request()));
    }

    private function relation(string $name): RelationInterface
    {
        return HasMany::make($name, $name)->build();
    }

    private function request(): JsonApiRequestInterface
    {
        // A stub (no expectations) — the backing never consults the request.
        return $this->createStub(JsonApiRequestInterface::class);
    }

    private function pagination(): RelationshipPagination
    {
        return new RelationshipPagination(new PageBasedPage([], 0, 1, 15), 'sort=-name');
    }
}
