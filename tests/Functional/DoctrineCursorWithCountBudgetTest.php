<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorIncludeBatchLoggingKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The SQL behind `?withCount=_self_` on a cursor page: one `COUNT` over the filtered
 * collection, with no keyset bound and no `LIMIT`, beside the usual `limit + 1` keyset
 * fetch. The rendered output is {@see CursorWithCountConformanceTestCase}'s concern.
 */
final class DoctrineCursorWithCountBudgetTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrineCursorShelves;

    private const string ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    protected static function getKernelClass(): string
    {
        return CursorIncludeBatchLoggingKernel::class;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function countedCursorPages(): iterable
    {
        yield 'primary' => ['/cursorWidgets?withCount=_self_&page[size]=2&filter[category]=news&sort=-releasedAt,-id'];
        yield 'primary, past a cursor' => ['/cursorWidgets?withCount=_self_&page[size]=2&filter[category]=news&page[after]=AFTER'];
        yield 'related' => ['/cursorShelves/1/widgets?withCount=_self_&page[size]=2&filter[category]=news'];
        yield 'pivot related' => ['/cursorShelves/1/pivotWidgets?withCount=_self_&page[size]=2&filter[category]=news'];
        yield 'linkage' => ['/cursorShelves/1/relationships/widgets?withCount=_self_&page[size]=2&filter[category]=news'];
        yield 'pivot linkage' => ['/cursorShelves/1/relationships/pivotWidgets?withCount=_self_&page[size]=2&filter[category]=news'];
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[DataProvider('countedCursorPages')]
    public function theTotalIsOneCountQueryBesideTheKeysetFetch(string $path): void
    {
        if (\str_contains($path, 'AFTER')) {
            $path = \str_replace('AFTER', $this->firstPageAfterCursor(), $path);
        }

        $statements = $this->captureStatements($path);

        $counts = \array_values(\array_filter($statements, static fn(string $s): bool => \stripos($s, 'COUNT(') !== false));
        self::assertCount(1, $counts, "the total is one COUNT query:\n" . \implode("\n", $statements));
        self::assertStringNotContainsStringIgnoringCase('LIMIT', $counts[0], 'the count is not windowed');

        foreach ($statements as $statement) {
            if (\stripos($statement, 'COUNT(') !== false || \stripos($statement, 'cursor_widget') === false) {
                continue;
            }
            self::assertMatchesRegularExpression('/LIMIT\s+3\b/i', $statement, 'every widget row read is the limit + 1 keyset probe, never the whole collection');
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anUncountedCursorPageRunsNoCount(): void
    {
        $statements = $this->captureStatements('/cursorWidgets?page[size]=2&filter[category]=news');

        self::assertSame([], \array_values(\array_filter($statements, static fn(string $s): bool => \stripos($s, 'COUNT(') !== false)));
    }

    private function firstPageAfterCursor(): string
    {
        $response = $this->handle('/cursorWidgets?page[size]=2&filter[category]=news');
        $document = $this->decode($response);
        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        $next = $links['next'] ?? null;
        $href = \is_array($next) ? ($next['href'] ?? null) : $next;
        self::assertIsString($href);
        \parse_str((string) \parse_url($href, \PHP_URL_QUERY), $query);
        $page = $query['page'] ?? null;
        self::assertIsArray($page);
        self::assertIsString($page['after'] ?? null);

        return $page['after'];
    }

    /**
     * @return list<string>
     */
    private function captureStatements(string $path): array
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $response = $this->handle('https://example.test' . $path, extraServer: ['HTTP_ACCEPT' => self::ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $logger->statements();
    }
}
