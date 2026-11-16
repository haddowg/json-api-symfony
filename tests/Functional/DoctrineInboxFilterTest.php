<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\InboxDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\InboxFixtures;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * {@see InboxFilterConformanceTestCase} against the Doctrine provider: a set filter is
 * an IS NULL or IS NOT NULL, a morph filter compares the declared columns on a MorphTo
 * and is one correlated EXISTS over the link rows of a MorphToMany.
 */
final class DoctrineInboxFilterTest extends InboxFilterConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return InboxDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (InboxFixtures::graph() as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aMorphToIdentifiedOnlyByAClosureFailsTheWarmUp(): void
    {
        static::ensureKernelShutdown();
        InboxDoctrineTestKernel::$closureSubject = true;

        try {
            $kernel = new InboxDoctrineTestKernel('test', false);
            (new \Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('The filter "subject" on JSON:API type "inbClosureItems" is a WhereMorph over the relation "subject", which the Doctrine provider cannot query');
            $this->expectExceptionMessage('identifiedByColumns(');
            KernelTestCase::bootKernel(['debug' => false]);
        } finally {
            InboxDoctrineTestKernel::$closureSubject = false;
            static::ensureKernelShutdown();
        }
    }
}
