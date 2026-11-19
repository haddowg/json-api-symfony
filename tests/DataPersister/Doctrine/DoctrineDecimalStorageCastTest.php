<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataPersister\Doctrine;

use haddowg\JsonApiBundle\DataPersister\Doctrine\DoctrineDecimalStorageCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineDecimalStorageCast::class)]
final class DoctrineDecimalStorageCastTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float, int, string}>
     */
    public static function numbers(): iterable
    {
        yield 'a float at its scale' => [19.99, 2, '19.99'];
        yield 'an int padded to the scale' => [20, 2, '20.00'];
        yield 'a whole float padded to the scale' => [20.0, 2, '20.00'];
        yield 'a float padded to the scale' => [1.5, 2, '1.50'];
        yield 'a float rounded to the scale' => [1.005, 2, '1.01'];
        yield 'a negative float' => [-19.99, 2, '-19.99'];
        yield 'a negative float that rounds to zero' => [-0.001, 2, '0.00'];
        yield 'a small float without an exponent' => [0.00001, 5, '0.00001'];
        yield 'a large float without an exponent' => [1e20, 2, '100000000000000000000.00'];
        yield 'a float with every digit it holds' => [123456789012345.678, 3, '123456789012345.670'];
        yield 'a float at a scale beyond its digits' => [1.1, 17, '1.10000000000000000'];
        yield 'a float with binary noise' => [0.1 + 0.2, 2, '0.30'];
        yield 'a scale of zero' => [2.5, 0, '3'];
        yield 'an int at a scale of zero' => [5, 0, '5'];
    }

    #[Test]
    #[DataProvider('numbers')]
    public function aNumberIsWrittenAsAPlainDecimalAtTheScale(int|float $value, int $scale, string $expected): void
    {
        self::assertSame($expected, DoctrineDecimalStorageCast::format($value, $scale));
    }
}
