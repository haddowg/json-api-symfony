<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\BasketEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\BasketLineEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\DecimalColumnDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\PriceEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A number written to a Doctrine `decimal` column reaches the entity as the decimal
 * string Doctrine reads the column as, at the column's scale. SQLite keeps a decimal as
 * a number and reads `20.00` back as `"20"`, so the stored form is checked as each
 * flush sees it rather than read back.
 */
final class DoctrineDecimalColumnTest extends JsonApiFunctionalTestCase
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    protected static function getKernelClass(): string
    {
        return DecimalColumnDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        PriceEntity::$flushedPrices = [];
        BasketLineEntity::$flushedDiscounts = [];
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    #[Test]
    #[Group('crud:create')]
    public function aNumberWrittenToAStringTypedDecimalColumnIsStoredAsItsDecimalString(): void
    {
        $this->create('{"price":19.99}');

        self::assertSame(['19.99'], PriceEntity::$flushedPrices);
        self::assertSame('19.99', $this->reloaded()->price);
    }

    #[Test]
    #[Group('crud:create')]
    public function theDecimalStringHasTheColumnsScale(): void
    {
        $this->create('{"price":20}');
        $this->create('{"price":1.5}');
        $this->create('{"wholePrice":7}');

        self::assertSame(['20.00', '1.50', '7.00'], PriceEntity::$flushedPrices);
    }

    #[Test]
    #[Group('crud:create')]
    public function aSetterTypedStringGetsTheDecimalString(): void
    {
        $this->create('{"amount":19.99}');

        self::assertSame('19.99', $this->reloaded()->getAmount());
    }

    #[Test]
    #[Group('crud:create')]
    public function aFloatTypedDecimalPropertyAndAFloatColumnStillGetAFloat(): void
    {
        $this->create('{"legacyPrice":19.99,"ratio":0.25}');

        $price = $this->reloaded();
        self::assertSame(19.99, $price->legacyPrice);
        self::assertSame(0.25, $price->ratio);
    }

    #[Test]
    #[Group('crud:create')]
    public function digitsBeyondWhatAFloatHoldsAreLostWhenTheBodyIsDecoded(): void
    {
        // A documented limitation: JSON decodes the number to the float 1.1 before any
        // field sees it, so the stored string carries the float's digits only.
        $this->create('{"rate":1.10000000000000001}');

        self::assertSame('1.10000000000000000', $this->flushedRate());
    }

    #[Test]
    #[Group('crud:update')]
    public function writingTheStoredValueAgainChangesNothingForDoctrine(): void
    {
        $this->create('{"price":19.99}');

        $this->update('{"price":19.99}');
        self::assertSame(['19.99'], PriceEntity::$flushedPrices);

        $this->update('{"price":21}');
        self::assertSame(['19.99', '21.00'], PriceEntity::$flushedPrices);
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aPivotFieldOnADecimalColumnIsStoredAsItsDecimalString(): void
    {
        $this->create('{"price":19.99}');
        $entityManager = $this->entityManager();
        $entityManager->persist(new BasketEntity(name: 'Weekly'));
        $entityManager->flush();
        $entityManager->clear();

        $response = $this->handleRaw('/baskets/1/relationships/prices', '{"data":[{"type":"prices","id":"1","meta":{"pivot":{"discount":2.5}}}]}', self::MEDIA_TYPE);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['2.50'], BasketLineEntity::$flushedDiscounts);
    }

    private function create(string $attributes): void
    {
        $created = $this->handleRaw('/prices', '{"data":{"type":"prices","attributes":' . $attributes . '}}', self::MEDIA_TYPE);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
    }

    private function update(string $attributes): void
    {
        $this->entityManager()->clear();
        $updated = $this->handleRaw('/prices/1', '{"data":{"type":"prices","id":"1","attributes":' . $attributes . '}}', self::MEDIA_TYPE, 'PATCH');
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
    }

    private function flushedRate(): ?string
    {
        $price = $this->entityManager()->find(PriceEntity::class, 1);
        self::assertInstanceOf(PriceEntity::class, $price);

        return $price->rate;
    }

    private function reloaded(): PriceEntity
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $price = $entityManager->find(PriceEntity::class, 1);
        self::assertInstanceOf(PriceEntity::class, $price);

        return $price;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
