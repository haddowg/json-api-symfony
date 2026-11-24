<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixSeed;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\ImNote;
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
    public function aMalformedGuidMatchesNothing(): void
    {
        self::assertSame(200, $this->handle('/im-notes?filter[guidOwner]=not-a-guid')->getStatusCode());
        self::assertSame(404, $this->handle('/im-guid-owners/not-a-guid/notes')->getStatusCode());
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
