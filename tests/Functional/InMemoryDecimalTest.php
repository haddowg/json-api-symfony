<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\DecimalColumnInMemoryTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn\PlainPrice;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The in-memory twin of {@see DoctrineDecimalColumnTest}. A plain object has no column
 * to cast for, so a `Decimal` writes the float it hydrates, and a member that needs a
 * string gets it from the field's own `deserializeUsing()`.
 */
final class InMemoryDecimalTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return DecimalColumnInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('crud:create')]
    public function aFloatTypedPropertyGetsTheFloatAndAConvertingFieldItsString(): void
    {
        $created = $this->handleRaw('/plainPrices', '{"data":{"type":"plainPrices","attributes":{"amount":19.99,"label":20}}}', 'application/vnd.api+json');
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());

        $provider = static::getContainer()->get('test.plain_prices_provider');
        self::assertInstanceOf(InMemoryDataProvider::class, $provider);
        $stored = $provider->store()->find('1');
        self::assertInstanceOf(PlainPrice::class, $stored);
        self::assertSame(19.99, $stored->amount);
        self::assertSame('20.00', $stored->label);
    }
}
