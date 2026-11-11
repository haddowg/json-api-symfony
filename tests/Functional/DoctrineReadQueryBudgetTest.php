<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\QueryCountingDoctrineKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Query-budget witnesses for the READ paths the windowed-include / `?withCount`
 * budget suites do not already cover (pre-v1 N+1 audit, 2026-06-17):
 *  - a plain (non-windowed) collection `?include` batches ONE related load per
 *    relation across the whole page, not one per parent;
 *  - a `WhereHas` existence filter compiles to a correlated `EXISTS` subquery — the
 *    `comment` table is never SELECTed standalone (no per-row probe, no fetch-join);
 *  - a paginated collection issues exactly one `COUNT` and one primary `SELECT`;
 *  - a withData() to-many that is not included loads its linkage in one batched query
 *    across the page, at the top level and one include level down.
 *
 * Boots the {@see QueryCountingDoctrineKernel}; assertions read the SQL back off the
 * {@see QueryCountingLogger}.
 */
final class DoctrineReadQueryBudgetTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineRelationships;

    private const string BASE_URI = 'https://example.test';

    protected static function getKernelClass(): string
    {
        return QueryCountingDoctrineKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPlainCollectionIncludeBatchesOneLoadPerRelationNotPerParent(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles (5 parents) ?include=comments — a plain compound document. The
        // preloader batches the INCLUDED comments in ONE query across the page
        // (SELECT … FROM comment WHERE article_id IN (…)), so the include load is O(1)
        // in the parent count — a per-parent preloader regression would emit five
        // `WHERE article_id = ?` loads instead. (Since the lazy-by-default flip in
        // core, a non-included to-many renders links-only and forces no per-parent
        // load at all — see the Finding-2 witness below — so this isolates the include
        // batch by its `IN (…)` fingerprint.)
        $response = $this->handle(self::BASE_URI . '/articles?include=comments');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $includeBatch = \array_filter(
            $this->selectsFrom($logger, 'comment'),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'in ('),
        );
        self::assertCount(
            1,
            $includeBatch,
            \sprintf(
                "the included comments must load in ONE batched IN(…) query, not one per parent; matched %d:\n%s",
                \count($includeBatch),
                \implode("\n", $includeBatch),
            ),
        );
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aWhereHasFilterCompilesToACorrelatedExistsNotAPerRowProbe(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles?filter[hasComments]=1 — the existence filter must compile to a
        // correlated EXISTS subquery folded into the article query (never a fetch-join
        // that multiplies rows, never a per-article probe to evaluate the predicate).
        // So the primary article SELECT carries an `EXISTS (… FROM comment …)`. (The
        // separate comment load for the result's linkage is not the filter, so this
        // asserts the filter shape directly: an EXISTS subquery referencing the comment
        // table.)
        $response = $this->handle(self::BASE_URI . '/articles?filter[hasComments]=1&sort=title');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $existsOverComments = \array_filter(
            $this->statementsContaining($logger, 'exists'),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'from comment'),
        );
        self::assertNotEmpty(
            $existsOverComments,
            "the existence filter must compile to an EXISTS subquery over the comment table; statements were:\n"
                . \implode("\n", $logger->statements()),
        );
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aRangeFilterRunsOnePrimarySelectWithTwoBoundPredicatesNoSubquery(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles?filter[idRange][min]=2&filter[idRange][max]=4 — the structured
        // Range compiles to TWO push-down `>=`/`<=` predicates on the SAME primary
        // article SELECT, NOT a correlated subquery or a per-row probe. So: exactly one
        // primary article SELECT, zero EXISTS subqueries (the fingerprint distinguishing
        // Range's two andWhere from a WhereHas-style correlated EXISTS).
        $response = $this->handle(self::BASE_URI . '/articles?filter[idRange][min]=2&filter[idRange][max]=4&sort=title');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $primarySelects = \array_filter(
            $this->selectsFrom($logger, 'article'),
            static fn(string $sql): bool => !\str_contains(\strtolower($sql), 'count('),
        );
        self::assertCount(
            1,
            $primarySelects,
            \sprintf("a Range filter must run exactly one primary article SELECT; ran %d:\n%s", \count($primarySelects), \implode("\n", $primarySelects)),
        );

        $existsSubqueries = $this->statementsContaining($logger, 'exists');
        self::assertSame(
            [],
            $existsSubqueries,
            "a Range filter must NOT emit an EXISTS subquery (it is two push-down bound predicates):\n" . \implode("\n", $existsSubqueries),
        );

        // The single primary SELECT carries both bound predicates (`>=` and `<=`).
        $primary = \strtolower($primarySelects[\array_key_first($primarySelects)] ?? '');
        self::assertStringContainsString('>=', $primary);
        self::assertStringContainsString('<=', $primary);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aLazyToManyOnACollectionForcesNoPerParentLinkageLoad(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles (5 parents) — a plain collection, no `?include`. The Finding-2
        // fix: a to-many is lazy by default, so a non-included to-many
        // renders its links only and never initialises its collection just to serialize
        // identifiers. The `pinnedComments` relation is an inverse one-to-many keyed by
        // the UNIQUE `pinned_article_id` FK on the comment table, so a per-parent
        // linkage load would fingerprint as `… FROM comment WHERE pinned_article_id = ?`
        // — five of them before the flip. After it, there are zero.
        $response = $this->handle(self::BASE_URI . '/articles');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // Fingerprint the WHERE predicate (`pinned_article_id = …`), not the column in
        // the SELECT list — the eager `comments` baseline (FK `article_id`) selects the
        // `pinned_article_id` column too, but only a pinnedComments load filters on it.
        $perParentPinnedLoads = \array_filter(
            $this->selectsFrom($logger, 'comment'),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'pinned_article_id ='),
        );
        self::assertSame(
            [],
            \array_values($perParentPinnedLoads),
            \sprintf(
                "a lazy to-many must force no per-parent linkage load; matched %d:\n%s",
                \count($perParentPinnedLoads),
                \implode("\n", $perParentPinnedLoads),
            ),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aWithDataToManyOnACollectionLoadsItsLinkageInOneQuery(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // GET /articles (5 parents), `comments` not included. It is a withData() to-many,
        // unscoped and unordered, so its linkage renders on every parent. The batcher
        // loads it for the whole page in one `article_id IN (…)` query; read off the
        // model it would initialise each parent's collection with its own
        // `article_id = ?` query. The sparse fieldset keeps flaggedComments, which
        // shares the column, out of the count.
        $response = $this->handle(self::BASE_URI . '/articles?fields[articles]=comments');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertLinkageRendered($response, 5);

        $this->assertOneCommentLinkageLoad($logger);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aWithDataToManyOneIncludeLevelDownLoadsItsLinkageInOneQuery(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // Every comment's article is included, and each included article renders its
        // withData() comments linkage: one batched load across the level.
        $response = $this->handle(self::BASE_URI . '/articleComments?include=article&fields[articles]=comments');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $included = $this->decode($response)['included'] ?? null;
        self::assertIsArray($included);
        self::assertNotEmpty($included);
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            self::assertSame('articles', $resource['type'] ?? null);
            $relationships = $resource['relationships'] ?? null;
            self::assertIsArray($relationships);
            $comments = $relationships['comments'] ?? null;
            self::assertIsArray($comments);
            self::assertArrayHasKey('data', $comments);
        }

        $this->assertOneCommentLinkageLoad($logger);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aWithDataToManyOnASingleResourceLoadsItsLinkageInOneQuery(): void
    {
        $logger = $this->logger();
        $logger->reset();

        $response = $this->handle(self::BASE_URI . '/articles/1?fields[articles]=comments');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $comments = $relationships['comments'] ?? null;
        self::assertIsArray($comments);
        self::assertNotEmpty($comments['data'] ?? null);

        self::assertCount(
            1,
            $this->selectsFrom($logger, 'comment'),
            "a single article's withData() comments must load in one query:\n" . \implode("\n", $logger->statements()),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCountFreePaginatedCollectionRunsZeroCountAndOnePrimarySelect(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // The count-free default: a bare `?page[size]=2` (no `?withCount`) now windows
        // count-free — ZERO COUNT queries, one primary SELECT (the windowed page over
        // the limit+1 probe).
        $response = $this->handle(self::BASE_URI . '/articles?page[size]=2');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $counts = $this->statementsContaining($logger, 'count(');
        self::assertCount(
            0,
            $counts,
            \sprintf("a count-free page must run ZERO COUNT; ran %d:\n%s", \count($counts), \implode("\n", $counts)),
        );

        $primarySelects = \array_filter(
            $this->selectsFrom($logger, 'article'),
            static fn(string $sql): bool => !\str_contains(\strtolower($sql), 'count('),
        );
        self::assertCount(
            1,
            $primarySelects,
            \sprintf("the page must run exactly one primary article SELECT; ran %d:\n%s", \count($primarySelects), \implode("\n", $primarySelects)),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function withCountSelfRunsExactlyOneCount(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // `?withCount=_self_` under the Countable profile opts into the total on the
        // countable() `articles` collection: EXACTLY one COUNT (the pre-window total),
        // fanned to meta.total + meta.page.total from that single count.
        $response = $this->handle(self::BASE_URI . '/articles?page[size]=2&withCount=_self_', extraServer: [
            'HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        self::assertSame(5, $meta['total'] ?? null);
        self::assertSame(5, $page['total'] ?? null);

        $counts = $this->statementsContaining($logger, 'count(');
        self::assertCount(
            1,
            $counts,
            \sprintf("`?withCount=_self_` must run exactly one COUNT; ran %d:\n%s", \count($counts), \implode("\n", $counts)),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aFetchAllCollectionRendersMetaTotalWithNoCountQuery(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // A resource that disables pagination (pagination() returns null) is fetched
        // whole, so its size is free — `meta.total` is rendered unconditionally with
        // NO COUNT query (the size comes from the materialised set). The
        // `unpaginatedArticles` resource serves the same article rows with no paginator.
        $response = $this->handle(self::BASE_URI . '/unpaginatedArticles');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertSame(5, $meta['total'] ?? null);
        self::assertArrayNotHasKey('page', $meta, 'fetch-all carries no page meta');

        // The `unpaginatedArticles` type maps the same `article` table; a fetch-all
        // runs no COUNT at all.
        $counts = $this->statementsContaining($logger, 'count(');
        self::assertCount(
            0,
            $counts,
            \sprintf("fetch-all must run NO COUNT; ran %d:\n%s", \count($counts), \implode("\n", $counts)),
        );
    }

    /**
     * Each of the `$parents` primary articles renders its `comments` linkage data.
     */
    private function assertLinkageRendered(Response $response, int $parents): void
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount($parents, $data);
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $relationships = $resource['relationships'] ?? null;
            self::assertIsArray($relationships);
            $comments = $relationships['comments'] ?? null;
            self::assertIsArray($comments);
            self::assertArrayHasKey('data', $comments);
        }
    }

    /**
     * The `comments` linkage loaded in one `article_id IN (…)` query and never per parent.
     */
    private function assertOneCommentLinkageLoad(QueryCountingLogger $logger): void
    {
        $comments = $this->selectsFrom($logger, 'comment');
        $perParent = \array_values(\array_filter(
            $comments,
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'article_id = '),
        ));
        self::assertSame([], $perParent, \sprintf(
            "a withData() to-many's linkage must not load once per parent; matched %d:\n%s",
            \count($perParent),
            \implode("\n", $perParent),
        ));

        $batched = \array_values(\array_filter(
            $comments,
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'article_id in ('),
        ));
        self::assertCount(1, $batched, \sprintf(
            "a withData() to-many's linkage must load in ONE batched query; matched %d:\n%s",
            \count($batched),
            \implode("\n", $batched),
        ));
    }

    /**
     * SELECT statements that read the given table.
     *
     * @return list<string>
     */
    private function selectsFrom(QueryCountingLogger $logger, string $table): array
    {
        return \array_values(\array_filter(
            $logger->statements(),
            static function (string $sql) use ($table): bool {
                $lower = \strtolower($sql);

                return \str_starts_with(\ltrim($lower), 'select') && \str_contains($lower, 'from ' . $table);
            },
        ));
    }

    /**
     * Statements whose lowercased SQL contains the given needle.
     *
     * @return list<string>
     */
    private function statementsContaining(QueryCountingLogger $logger, string $needle): array
    {
        return \array_values(\array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), $needle),
        ));
    }

    private function logger(): QueryCountingLogger
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);

        return $logger;
    }
}
