<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\HideEveryRowFilter;
use haddowg\JsonApiBundle\Tests\Functional\App\IdSource\RecordingSqlLogger;
use haddowg\JsonApiBundle\Tests\Functional\App\IdSource\SqlRecordingIdSourceTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * A create naming a client id runs inside the write's transaction, so its insert sits in a
 * savepoint of its own: when the insert loses an id race, the persister rolls back to that
 * savepoint before it looks the id up, which a database such as PostgreSQL requires after a
 * failed statement. SQLite doesn't refuse statements after a failed one, so the test reads
 * the SQL the connection ran rather than reproducing PostgreSQL's refusal.
 */
final class DoctrineClientIdRaceSavepointTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return SqlRecordingIdSourceTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    #[Test]
    public function aLostIdRaceRollsBackToTheInsertsSavepointBeforeTheLookup(): void
    {
        self::assertSame(201, $this->handle('/tokens', 'POST', [
            'data' => ['type' => 'tokens', 'id' => 'raced-key', 'attributes' => ['value' => 'v']],
        ])->getStatusCode());

        // The lookup misses the stored row, so the insert meets the primary key.
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $entityManager->getConfiguration()->addFilter('hide_every_row', HideEveryRowFilter::class);
        $entityManager->getFilters()->enable('hide_every_row');
        RecordingSqlLogger::$statements = [];

        $response = $this->handle('/tokens', 'POST', [
            'data' => ['type' => 'tokens', 'id' => 'raced-key', 'attributes' => ['value' => 'w']],
        ]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());

        $statements = RecordingSqlLogger::$statements;
        $savepoint = $this->indexOf($statements, '/^SAVEPOINT JSONAPI_CREATE_\d+$/');
        $insert = $this->indexOf($statements, '/^INSERT INTO /');
        $rollback = $this->indexOf($statements, '/^ROLLBACK TO SAVEPOINT JSONAPI_CREATE_\d+$/');
        $lookup = $this->indexOf($statements, '/^SELECT 1 FROM /');
        self::assertTrue($savepoint < $insert && $insert < $rollback && $rollback < $lookup, \implode("\n", $statements));
        self::assertSame(0, $entityManager->getConnection()->getTransactionNestingLevel());
    }

    /**
     * @param list<string> $statements
     */
    private function indexOf(array $statements, string $pattern): int
    {
        foreach ($statements as $index => $statement) {
            if (\preg_match($pattern, $statement) === 1) {
                return $index;
            }
        }

        self::fail(\sprintf("No statement matches %s:\n%s", $pattern, \implode("\n", $statements)));
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
