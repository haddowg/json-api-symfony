<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InMemoryDataProviderTest extends TestCase
{
    #[Test]
    public function itSupportsOnlyItsOwnType(): void
    {
        $provider = new InMemoryDataProvider('articles', []);

        self::assertTrue($provider->supports('articles'));
        self::assertFalse($provider->supports('comments'));
    }

    #[Test]
    public function itFetchesOneById(): void
    {
        $one = new \stdClass();
        $provider = new InMemoryDataProvider('articles', ['1' => $one]);

        self::assertSame($one, $provider->fetchOne('articles', '1'));
        self::assertNull($provider->fetchOne('articles', '999'));
    }

    #[Test]
    public function itFetchesTheWholeCollection(): void
    {
        $one = new \stdClass();
        $two = new \stdClass();
        $provider = new InMemoryDataProvider('articles', ['1' => $one, '2' => $two]);

        $collection = $provider->fetchCollection('articles', $this->emptyQuery());

        self::assertSame([$one, $two], \is_array($collection) ? $collection : \iterator_to_array($collection));
    }

    private function emptyQuery(): QueryParameters
    {
        return new QueryParameters([], [], [], [], []);
    }
}
