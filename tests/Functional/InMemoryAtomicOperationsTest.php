<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Atomic\AtomicInMemoryFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Atomic\AtomicInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Atomic\ThrowingAfterCreateCommentResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see AtomicOperationsConformanceTestCase} against the in-memory providers: the
 * witness for the Doctrine atomic path, running the whole batch against the shared
 * in-memory object graph (with the cross-store snapshot coordinator). `afterBoot()`
 * resets the factory so each test boots a fresh, unmutated graph.
 *
 * It also carries the in-memory-only cases: a batch touching the non-transactional
 * `tags` type is refused before any write, and a genuinely attribute-less `add`
 * carrying a `lid` (infeasible on Doctrine, whose constructor-less instantiation
 * skips NOT-NULL attribute defaults).
 */
final class InMemoryAtomicOperationsTest extends AtomicOperationsConformanceTestCase
{
    protected function afterBoot(): void
    {
        AtomicInMemoryFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return AtomicInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aBatchTouchingANonTransactionalTypeIsRefusedBeforeAnyWrite(): void
    {
        // Op 0 is a valid article update; op 1 touches `tags`, whose persister is NOT
        // transactional — so the whole batch is refused in pre-flight, before op 0
        // writes anything.
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'articles', 'id' => '1'],
                'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'Must not persist']],
            ],
            [
                'op' => 'add',
                'data' => ['type' => 'tags', 'attributes' => ['name' => 'new-tag']],
            ],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ATOMIC_OPERATIONS_NOT_SUPPORTED', $this->errors($response)[0]['code'] ?? null);

        // Pre-flight refused before any write: article 1 keeps its original title.
        self::assertSame('JSON:API in PHP', $this->attributesOf($this->handle('/articles/1'))['title'] ?? null);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAttributeLessAddCarryingALidIsCreatedAndReferenceable(): void
    {
        // An `add` whose `data` is a resource object with a `lid` but NO `attributes` and
        // NO `relationships` is a fully valid create (all attributes optional/defaulted).
        // Its OWN top-level lid must NOT be resolved as a forward reference (it is
        // registered after the create), so a later op can reference it by that lid. The
        // shape is indistinguishable from a bare to-one identifier without the target, so
        // the executor drives the disambiguation from the (resource, not relationship)
        // target rather than from `data`'s shape — the fix for the regression where this
        // wrongly `400`ed as a forward reference (LOCAL_ID_NOT_FOUND).
        //
        // In-memory-only: the Doctrine reference persister instantiates entities
        // constructor-less (ADR 0029), so the entity's NOT-NULL attribute defaults do not
        // run and a genuinely zero-attribute create is infeasible there — an orthogonal
        // storage constraint, not a property of this (storage-agnostic) executor fix.
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => ['type' => 'authors', 'lid' => 'blank'],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => 'authors', 'lid' => 'blank'],
                'data' => ['type' => 'authors', 'attributes' => ['name' => 'Filled in later']],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $results = $this->results($response);
        self::assertCount(2, $results);

        // Op 0 created an author with a real id (no attributes supplied).
        $created = $results[0]['data'] ?? null;
        self::assertIsArray($created);
        self::assertSame('authors', $created['type'] ?? null);
        $id = $created['id'] ?? null;
        self::assertIsString($id);

        // Op 1 resolved the create's lid in its `ref` and updated the same author.
        self::assertSame($id, $this->member($results[1], 'data', 'id'));
        self::assertSame('Filled in later', $this->member($results[1], 'data', 'attributes', 'name'));

        // Persisted: the re-read author carries the later-supplied name.
        self::assertSame('Filled in later', $this->attributesOf($this->handle('/authors/' . $id))['name'] ?? null);
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aThrowingPostCommitHookDoesNotFailACommittedBatch(): void
    {
        // The batch creates a `BOOM` comment, whose post-commit `afterCreate` hook
        // throws (the ThrowingAfterCreateCommentResource). Under atomic, that After* hook
        // is DEFERRED to the post-commit drain — by which point the batch has already
        // durably committed. A throwing post-commit hook must NOT turn the successful
        // batch into a 500: the executor logs it (best-effort) and still returns the
        // 200 atomic:results, and the comment IS persisted (bundle ADR 0088).
        //
        // In-memory-only: the throwing-hook `comments` resource is wired on this kernel
        // (the Doctrine kernel keeps the plain comment resource).
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => [
                    'type' => 'comments',
                    'attributes' => ['body' => ThrowingAfterCreateCommentResource::BOOM],
                ],
            ],
        ]);

        // The committed batch succeeds despite the throwing post-commit hook.
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $results = $this->results($response);
        self::assertCount(1, $results);
        $created = $results[0]['data'] ?? null;
        self::assertIsArray($created);
        self::assertSame('comments', $created['type'] ?? null);
        $id = $created['id'] ?? null;
        self::assertIsString($id);

        // The comment was durably committed — the hook threw AFTER the commit, so it
        // rolled nothing back.
        self::assertSame(
            ThrowingAfterCreateCommentResource::BOOM,
            $this->attributesOf($this->handle('/comments/' . $id))['body'] ?? null,
        );
    }
}
