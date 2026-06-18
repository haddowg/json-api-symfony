<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;

/**
 * The shared bounded-fetch PROOF for the windowed-include batch (bundle ADR 0065): a
 * windowed include over a LARGE relation (article 1 with 50 comments) inspects the
 * {@see QueryCountingLogger}'s captured SQL to prove the over-fetch is GONE — the 6a batch
 * materialised every parent's whole related set then sliced in PHP.
 *
 *  - {@see DoctrineWindowedIncludeBatchBudgetOnTest} (window_functions on) — exactly ONE
 *    native ROW_NUMBER statement, bounded `jsonapi_rn <= :limit`, with the real per-parent
 *    total riding `COUNT(*) OVER` the same scan;
 *  - {@see DoctrineWindowedIncludeBatchBudgetOffTest} (off) — NO window function, the
 *    per-parent bounded fallback running real `LIMIT` push-downs, strictly better than 6a.
 */
abstract class DoctrineWindowedIncludeBatchBudgetTestCase extends JsonApiFunctionalTestCase
{
    use SeedsLargeWindowedRelations;

    protected const string BASE_URI = 'https://example.test';

    protected const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    /**
     * Negotiates BOTH the Relationship-Queries (windowing) and Countable (`?withCount`)
     * profiles — the count rides the bounded scan only when the count is opted into (G21).
     */
    protected const string COUNTING_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . ' ' . CountableProfile::URI . '"';

    /**
     * The captured SQL of a windowed pinnedComments include over article 1's 50 comments,
     * with `?withCount` opting into the count so the `COUNT(*) OVER` rides the bounded scan.
     *
     * @return list<string>
     */
    protected function windowedIncludeStatements(): array
    {
        return $this->captureStatements('/articles?include=pinnedComments&withCount=pinnedComments&relatedQuery[pinnedComments][sort]=-body');
    }

    /**
     * The captured SQL of a FILTERED windowed pinnedComments include (a relatedQuery
     * `filter[bodyContains]` + a sort + the page-1 window), counted via `?withCount` — the
     * case the native batch now folds onto ONE bounded query (bundle ADR 0066).
     *
     * @return list<string>
     */
    protected function filteredWindowedIncludeStatements(): array
    {
        return $this->captureStatements(
            '/articles?include=pinnedComments&withCount=pinnedComments'
            . '&relatedQuery[pinnedComments][filter][bodyContains]=comment-4&relatedQuery[pinnedComments][sort]=-body',
        );
    }

    /**
     * Captures the SQL the request at `$path` runs through the {@see QueryCountingLogger}.
     *
     * @return list<string>
     */
    private function captureStatements(string $path): array
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $response = $this->handle(
            self::BASE_URI . $path,
            extraServer: ['HTTP_ACCEPT' => self::COUNTING_ACCEPT],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $logger->statements();
    }
}
