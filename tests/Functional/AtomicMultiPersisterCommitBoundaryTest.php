<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Atomic\AtomicFailingCommitFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Atomic\AtomicFailingCommitTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The witness for the executor's multi-persister commit boundary (adversarial-review
 * finding 3): the Atomic Operations all-or-nothing guarantee is scoped to a SINGLE
 * transactional persister per batch.
 *
 * A batch spanning two distinct transactional persisters commits them in turn; there
 * is no two-phase commit across them, so a later commit failure cannot undo an
 * earlier durable one. This kernel forces exactly that: `authors` commits normally,
 * then the `articles` persister's commit throws. The executor must roll back the
 * persisters that have NOT yet committed (the article write) and re-raise (the batch
 * renders as a rolled-back error), while the already-committed `authors` write stands
 * — the documented limitation, asserted honestly rather than papered over.
 *
 * The default single-persister batch (one shared Doctrine EntityManager, or the
 * cross-store in-memory coordinator whose commit cannot fail) is fully atomic and is
 * covered by {@see AtomicOperationsConformanceTestCase}.
 */
final class AtomicMultiPersisterCommitBoundaryTest extends JsonApiFunctionalTestCase
{
    protected function afterBoot(): void
    {
        AtomicFailingCommitFactory::reset();
    }

    protected static function getKernelClass(): string
    {
        return AtomicFailingCommitTestKernel::class;
    }

    #[Test]
    #[Group('spec:atomic')]
    public function aLaterCommitFailureRollsBackOnlyTheNotYetCommittedPersisters(): void
    {
        // Op 0 adds an author (its persister commits normally, FIRST); op 1 adds an
        // article (its persister's commit throws, SECOND). The commit loop commits the
        // author durably, then the article commit fails.
        $response = $this->atomic([
            [
                'op' => 'add',
                'data' => [
                    'type' => 'authors',
                    'attributes' => ['name' => 'Grace Hopper'],
                ],
            ],
            [
                'op' => 'add',
                'data' => [
                    'type' => 'articles',
                    'attributes' => ['title' => 'A batch article', 'body' => 'Body.', 'category' => 'news'],
                ],
            ],
        ]);

        // The commit failure surfaces as a rolled-back error document (the failing
        // persister threw an application error during commit).
        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());

        // A commit-time failure is still a document produced under the applied extension,
        // so it advertises the atomic ext on its Content-Type (as every atomic error does).
        self::assertStringContainsString(
            'ext="https://jsonapi.org/ext/atomic"',
            (string) $response->headers->get('Content-Type'),
        );

        // The not-yet-committed persister rolled back: no new article was kept (the
        // article store is restored to its 5 seeded rows, so id 6 does not exist).
        self::assertSame(404, $this->handle('/articles/6')->getStatusCode());

        // The already-committed persister's write STANDS — the documented
        // single-transactional-persister-per-batch limitation: the new author (id 3,
        // past the two seeded) is durable even though the batch failed at commit.
        self::assertSame(200, $this->handle('/authors/3')->getStatusCode());
        self::assertSame('Grace Hopper', $this->attributesOf($this->handle('/authors/3'))['name'] ?? null);
    }

    // ---------------------------------------------------------------------------
    // Helpers (mirrors AtomicOperationsConformanceTestCase)
    // ---------------------------------------------------------------------------

    /**
     * Issues a `POST /operations` atomic batch with the atomic `ext` media-type on
     * both `Content-Type` and `Accept`.
     *
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

        $request = Request::create(
            '/operations',
            'POST',
            server: ['CONTENT_TYPE' => $ext, 'HTTP_ACCEPT' => $ext],
            content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR),
        );
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);

        $this->restoreHandlers();

        return $response;
    }

    /**
     * The `data.attributes` of a primary-resource document, narrowed.
     *
     * @return array<string, mixed>
     */
    private function attributesOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}
