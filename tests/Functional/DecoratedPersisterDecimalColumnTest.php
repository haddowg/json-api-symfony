<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\DateTimeOnlyDecoratingPersister;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\DecoratedPersisterDecimalTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\PriceEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A persister that decorates the Doctrine persister and implements only
 * StoresDateTimesInterface still compiles and writes, and an attribute written to a
 * decimal column still gets its decimal string through core's storage cast.
 */
final class DecoratedPersisterDecimalColumnTest extends JsonApiFunctionalTestCase
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    protected static function getKernelClass(): string
    {
        return DecoratedPersisterDecimalTestKernel::class;
    }

    protected function afterBoot(): void
    {
        PriceEntity::$flushedPrices = [];
        DateTimeOnlyDecoratingPersister::$calls = [];
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    #[Test]
    #[Group('crud:create')]
    public function aDecoratingPersisterWithoutTheDecimalInterfaceStillWrites(): void
    {
        $created = $this->handleRaw('/prices', '{"data":{"type":"prices","attributes":{"price":19.99}}}', self::MEDIA_TYPE);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $this->entityManager()->clear();
        $updated = $this->handleRaw('/prices/1', '{"data":{"type":"prices","id":"1","attributes":{"price":21}}}', self::MEDIA_TYPE, 'PATCH');
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());

        self::assertSame(['create', 'update'], DateTimeOnlyDecoratingPersister::$calls);
        self::assertSame(['19.99', '21.00'], PriceEntity::$flushedPrices);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
