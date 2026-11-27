<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixSeed;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\ImGuidOwner;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\ImGuidSeat;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\ImNote;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\ImUuidOwner;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see IdMatrixConformanceTestCase} on Doctrine. On sqlite a `uuid` and a `ulid` are 16
 * bytes and the custom type an integer, so every key compares in its database form.
 */
final class DoctrineIdMatrixTest extends IdMatrixConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return IdMatrixDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        parent::afterBoot();

        $entityManager = $this->entityManager();
        $schema = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        foreach (IdMatrixSeed::build() as $rows) {
            foreach ($rows as $row) {
                $entityManager->persist($row);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aPolymorphicWriteStoresTheMappedTypeAndTheKey(): void
    {
        self::assertSame(200, $this->handle('/im-posts/2/relationships/notes', 'POST', ['data' => [['type' => 'im-notes', 'id' => '4']]])->getStatusCode());

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $note = $entityManager->find(ImNote::class, 4);
        self::assertInstanceOf(ImNote::class, $note);
        self::assertSame('im-posts', $note->subjectType);
        self::assertSame(2, $note->subjectId);
    }

    #[Test]
    public function aGuidStoredInCapitalsIsAddressedAndRenderedInLowerCase(): void
    {
        $wire = \strtolower(self::CAPITAL_GUID);
        $this->seedCapitalGuidOwner();

        foreach ([$wire, self::CAPITAL_GUID] as $id) {
            self::assertSame($wire, $this->dataOf($this->handle('/im-guid-owners/' . $id))['id'] ?? null, $id);
        }
        self::assertSame(['5'], $this->idsOf($this->handle('/im-guid-owners/' . $wire . '/notes')));
        self::assertSame(['5'], $this->idsOf($this->handle('/im-guid-owners/' . $wire . '/relationships/notes')));

        $note = $this->decode($this->handle('/im-notes/5?include=ref'));
        self::assertSame(['type' => 'im-guid-owners', 'id' => $wire], $this->relationshipData($note['data'] ?? null, 'ref'));
        $included = $note['included'] ?? null;
        self::assertIsArray($included);
        self::assertSame([$wire], \array_column($included, 'id'));

        $updated = $this->handle('/im-guid-owners/' . $wire, 'PATCH', ['data' => ['type' => 'im-guid-owners', 'id' => $wire, 'attributes' => ['name' => 'Renamed']]]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
        self::assertSame($wire, $this->dataOf($updated)['id'] ?? null);

        $added = $this->handle('/im-guid-owners/' . $wire . '/relationships/notes', 'POST', ['data' => [['type' => 'im-notes', 'id' => '4']]]);
        self::assertSame(200, $added->getStatusCode(), (string) $added->getContent());
        self::assertSame(['4', '5'], $this->idsOf($this->handle('/im-guid-owners/' . $wire . '/relationships/notes')));
        self::assertSame(self::CAPITAL_GUID, $this->entityManager()->getConnection()->fetchOne('SELECT guidOwnerId FROM im_note WHERE id = 4'));

        self::assertSame(204, $this->handle('/im-guid-owners/' . $wire, 'DELETE')->getStatusCode());
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(ImGuidOwner::class, self::CAPITAL_GUID));
    }

    #[Test]
    public function aGuidOwnerWhoseNotesKeepItsKeyInBothCasesIsOnePartition(): void
    {
        $this->seedCapitalGuidOwner();
        $entityManager = $this->entityManager();
        $entityManager->persist(new ImNote(6, 'Six', null, null, null, null, \strtolower(self::CAPITAL_GUID), null, null, null, 'im-posts', 2));
        $entityManager->flush();
        $entityManager->clear();

        $statements = $this->statementsOf('/im-guid-owners?include=notes');
        $notes = \array_values(\array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'from im_note') && \str_contains($sql, 'guidownerid in')));

        $rows = $this->decode($this->handle('/im-guid-owners?include=notes'))['data'] ?? null;
        self::assertIsArray($rows);
        $owners = [];
        foreach ($rows as $owner) {
            self::assertIsArray($owner);
            self::assertIsString($owner['id'] ?? null);
            $owners[$owner['id']] = $owner;
        }
        $data = $this->relationshipData($owners[\strtolower(self::CAPITAL_GUID)] ?? null, 'notes');
        self::assertIsArray($data);
        $ids = \array_column($data, 'id');
        \sort($ids);
        self::assertSame(['5', '6'], $ids);

        self::assertCount(1, $notes, \implode("\n", $statements));
        self::assertStringContainsString('partition by', $notes[0]);
        self::assertStringContainsString('lower(i0_.guidownerid) as', $notes[0]);
        // The column itself is compared as stored, so an index on it serves the query.
        self::assertStringContainsString('i0_.guidownerid in (', $notes[0]);
    }

    protected function seedCapitalGuidOwner(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(new ImGuidOwner(self::CAPITAL_GUID, 'Capital'));
        $note = new ImNote(5, 'Five', null, null, null, null, self::CAPITAL_GUID, null, null, null, 'im-posts', 2);
        $note->refType = 'im-guid-owners';
        $note->refId = self::CAPITAL_GUID;
        $entityManager->persist($note);
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(\Symfony\Component\HttpFoundation\Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return list<mixed>
     */
    private function idsOf(\Symfony\Component\HttpFoundation\Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $ids = \array_column($data, 'id');
        \sort($ids);

        return $ids;
    }

    private function relationshipData(mixed $resource, string $name): mixed
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$name] ?? null;
        self::assertIsArray($relationship);

        return $relationship['data'] ?? null;
    }

    #[Test]
    public function aGuidStoredInCapitalsRendersItsPivotOnAnActionResponse(): void
    {
        $this->seatAShoutyOwner();
        $wire = \strtolower(self::SHOUTY);
        $own = [IdMatrixSeed::wireId('uuid', 0) => ['rank' => 1], IdMatrixSeed::wireId('uuid', 1) => ['rank' => 2]];
        \ksort($own);

        foreach ([$wire, self::SHOUTY] as $id) {
            foreach ([
                $this->fetch('/im-guid-owners/' . $id . '?include=peers'),
                $this->invoke('/im-guid-owners/' . $id . '/-actions/show?include=peers'),
            ] as $document) {
                self::assertSame($wire, $this->documentData($document)['id'] ?? null, $id);
                self::assertSame($own, $this->seatPivots($this->documentData($document)), $id);
            }
        }
    }

    #[Test]
    public function aMalformedGuidMatchesNothing(): void
    {
        self::assertSame(200, $this->handle('/im-notes?filter[guidOwner]=not-a-guid')->getStatusCode());
        self::assertSame(404, $this->handle('/im-guid-owners/not-a-guid/notes')->getStatusCode());
    }

    #[Test]
    public function aMorphManyWhoseFormsDifferOnlyInCaseReadsAPageOfParentsInOneWindowedQuery(): void
    {
        $statements = $this->statementsOf('/im-uuid-owners?include=refNotes');
        $refs = \array_values(\array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'reftype in')));

        self::assertCount(1, $refs, \implode("\n", $statements));
        self::assertStringContainsString('row_number() over (partition by', $refs[0]);
        self::assertStringContainsString('lower(i0_.refid) as', $refs[0]);
        // The column itself is compared as stored, so an index on it serves the query.
        self::assertStringContainsString('i0_.refid in (', $refs[0]);
        self::assertStringNotContainsString('lower(i0_.refid) in', $refs[0]);
    }

    #[Test]
    public function aMorphManyWhoseEncoderStoresAnotherFormReadsEachParentOnItsOwn(): void
    {
        $statements = $this->statementsOf('/im-prefix-owners?include=refNotes');
        $refs = \array_values(\array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'reftype in')));

        self::assertSame([], \array_values(\array_filter($refs, static fn(string $sql): bool => \str_contains($sql, 'row_number()'))));
        self::assertCount(3, $refs);
    }

    private const string SHOUTY = 'C0FFEE00-1E00-4A4E-9C55-7A4E0000000A';

    /**
     * Stores a guid owner keyed in capitals, seated beside the first two uuid owners.
     */
    private function seatAShoutyOwner(): void
    {
        $entityManager = $this->entityManager();
        $shouty = new ImGuidOwner(self::SHOUTY, 'Shouty');
        $entityManager->persist($shouty);
        foreach ([0 => 1, 1 => 2] as $index => $rank) {
            $peer = $entityManager->find(ImUuidOwner::class, IdMatrixSeed::keys()['uuid'][$index]);
            self::assertInstanceOf(ImUuidOwner::class, $peer);
            $entityManager->persist(new ImGuidSeat($shouty, $peer, $rank));
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoke(string $path): array
    {
        $response = $this->handle($path, 'POST');
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function documentData(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        /** @var array<string, mixed> $data */

        return $data;
    }

    /**
     * Each `peers` linkage member's `meta.pivot` on `$resource`, keyed by id in id order.
     *
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function seatPivots(array $resource): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships, (string) \json_encode($resource));
        $peers = $relationships['peers'] ?? null;
        self::assertIsArray($peers);
        $data = $peers['data'] ?? null;
        self::assertIsArray($data, (string) \json_encode($peers));

        $pivots = [];
        foreach ($data as $member) {
            self::assertIsArray($member);
            self::assertIsString($member['id'] ?? null);
            $meta = $member['meta'] ?? null;
            self::assertIsArray($meta, (string) \json_encode($member));
            $pivots[$member['id']] = $meta['pivot'] ?? null;
        }
        \ksort($pivots);

        return $pivots;
    }

    /**
     * @return list<string>
     */
    private function statementsOf(string $path): array
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();
        self::assertSame(200, $this->handle($path)->getStatusCode());

        return \array_map('\strtolower', $logger->statements());
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
