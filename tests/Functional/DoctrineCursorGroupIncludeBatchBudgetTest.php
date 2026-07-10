<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\CursorGroupFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\CursorWidgetFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorGroupEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorIncludeBatchLoggingKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorWidgetEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorWidgetEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The SQL PROOF for the INVERSE-FK (Doctrine `OneToMany`) cursor (keyset) windowed-include
 * batch (bundle ADR 0118): a cursor-resolved COLLECTION include over `cursorGroups` →
 * `widgets` (the related widget carries the owning `group_id` FK) inspects the
 * {@see QueryCountingLogger}'s captured SQL to prove the N per-parent keyset queries collapsed
 * to ONE bounded `ROW_NUMBER()` window — the inverse-FK complement of the join-table
 * {@see DoctrineCursorIncludeBatchBudgetTest} (this branch roots on the related entity and
 * partitions by the owning FK, with NO join table).
 *
 * Byte-identical rendered output against the in-memory witness is the
 * {@see CursorIncludeBatchConformanceTestCase} suite's concern; here the concern is the SQL
 * shape and the statement count.
 */
final class DoctrineCursorGroupIncludeBatchBudgetTest extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    /** Negotiates the Relationship-Queries (windowing) profile that windows an included to-many to page 1. */
    private const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    protected static function getKernelClass(): string
    {
        return CursorIncludeBatchLoggingKernel::class;
    }

    /**
     * Seeds the shared widgets, then the inverse-FK groups — each member widget carries the
     * owning `group_id` FK (set on the OWNING side), so the cursor include over `/cursorGroups`
     * runs the inverse-FK single-window shape. (The join-table shelves are not seeded here; the
     * join-table window proof lives in {@see DoctrineCursorIncludeBatchBudgetTest}.)
     */
    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Widgets in fixture id order so the AUTO column assigns sequential ids 1..N.
        foreach (CursorWidgetFixtures::data() as $row) {
            CursorWidgetEntityFactory::createOne([
                'category' => $row['category'],
                'priority' => $row['priority'],
                'releasedAt' => $row['releasedAt'] !== null ? new \DateTimeImmutable($row['releasedAt']) : null,
            ]);
        }

        foreach (CursorGroupFixtures::data() as $widgetIds) {
            $group = new CursorGroupEntity();
            $entityManager->persist($group);
            foreach ($widgetIds as $widgetId) {
                $widget = $entityManager->find(CursorWidgetEntity::class, $widgetId);
                \assert($widget instanceof CursorWidgetEntity);
                $widget->group = $group;
            }
        }
        $entityManager->flush();

        $entityManager->clear();
    }

    #[Test]
    #[Group('spec:profiles')]
    #[Group('spec:fetching-pagination')]
    public function anInverseFkCursorIncludeCollapsesToOneBoundedRowNumberWindow(): void
    {
        // A cursor include over the whole page of groups sorted on the NULLABLE `priority`.
        $windowStatements = \array_values(\array_filter(
            $this->captureStatements('/cursorGroups?include=widgets&relatedQuery[widgets][sort]=priority'),
            static fn(string $s): bool => \stripos($s, 'ROW_NUMBER') !== false,
        ));

        // The N→1 collapse: exactly ONE ROW_NUMBER window for the whole page of groups, NEVER
        // one keyset query per parent.
        self::assertCount(
            1,
            $windowStatements,
            "an inverse-FK cursor include is ONE bounded ROW_NUMBER window, not a per-parent loop:\n" . \implode("\n", $windowStatements),
        );

        $window = $windowStatements[0];

        // The INVERSE-FK shape roots the inner query on the RELATED widget and scopes by the
        // owning `group_id` FK — NO join table (the distinguishing mark from the m2m proof).
        self::assertMatchesRegularExpression(
            '/group_id IN/i',
            $window,
            'the inner query scopes by the owning group_id FK (the inverse-FK shape roots on the widget)',
        );
        self::assertStringNotContainsStringIgnoringCase(
            'cursor_shelf_widget',
            $window,
            'the inverse-FK window joins NO m2m join table',
        );

        // The outer window partitions by the generated owning-FK scalar alias read off the
        // inner RSM (w.sclr_N).
        self::assertMatchesRegularExpression(
            '/PARTITION BY w\.\w+/i',
            $window,
            'the outer window partitions by the generated owning-FK discriminator alias',
        );

        // The forced NULL=largest keyset ordering term composed VERBATIM inside `ROW_NUMBER()
        // OVER (… ORDER BY …)`, over the generated sort scalar alias — the disproven-blocker
        // proof for the inverse-FK shape too.
        self::assertMatchesRegularExpression(
            '/CASE WHEN w\.\w+ IS NULL THEN 1 ELSE 0 END ASC/i',
            $window,
            'the keyset CASE WHEN … IS NULL … term composes inside the ROW_NUMBER OVER clause',
        );

        // A cursor page is count-free by definition: bounded by `jsonapi_rn <= :cap` (limit + 1)
        // with NO `COUNT(*) OVER` total.
        self::assertMatchesRegularExpression(
            '/jsonapi_rn\s*<=/i',
            $window,
            'the outer wrap bounds rows to jsonapi_rn <= limit + 1 (the count-free hasMore probe)',
        );
        self::assertStringNotContainsStringIgnoringCase(
            'COUNT(*) OVER',
            $window,
            'a cursor page is count-free: no COUNT(*) OVER total rides the scan',
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

        $response = $this->handle(self::BASE_URI . $path, extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $logger->statements();
    }
}
