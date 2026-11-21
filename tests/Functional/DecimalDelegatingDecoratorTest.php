<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\DateTimeOnlyDecoratingPersister;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\DecimalDelegatingDecoratorTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\PriceEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\TrayEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\TrayLineEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A persister that decorates the Doctrine persister and implements
 * StoresDecimalsInterface by delegation gets the decimal string for a pivot value the
 * bundle writes to a declared link row.
 */
final class DecimalDelegatingDecoratorTest extends JsonApiFunctionalTestCase
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    protected static function getKernelClass(): string
    {
        return DecimalDelegatingDecoratorTestKernel::class;
    }

    protected function afterBoot(): void
    {
        TrayLineEntity::$flushedDiscounts = [];
        DateTimeOnlyDecoratingPersister::$calls = [];
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aLinkRowPivotDecimalGoesThroughTheDecoratorAsItsDecimalString(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(new PriceEntity(price: '19.99'));
        $entityManager->persist(new TrayEntity(name: 'Weekly'));
        $entityManager->flush();
        $entityManager->clear();

        $response = $this->handleRaw('/trays/1/relationships/prices', '{"data":[{"type":"prices","id":"1","meta":{"pivot":{"discount":2.5}}}]}', self::MEDIA_TYPE);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertContains('decimalForColumn', DateTimeOnlyDecoratingPersister::$calls);
        self::assertSame(['2.50'], TrayLineEntity::$flushedDiscounts);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
