<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes\Setting;
use haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes\ValueTypesInMemoryTestKernel;

final class InMemoryUnionTest extends UnionConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ValueTypesInMemoryTestKernel::class;
    }

    protected function holdsTopLevelDatesAsStrings(): bool
    {
        return false;
    }

    protected function stored(string $id, string $property): mixed
    {
        $provider = static::getContainer()->get('test.settings_provider');
        self::assertInstanceOf(InMemoryDataProvider::class, $provider);
        $setting = $provider->store()->find($id);
        self::assertInstanceOf(Setting::class, $setting);

        return $setting->{$property};
    }
}
