<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes\Submission;
use haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes\ValueTypesInMemoryTestKernel;

final class InMemoryValueTypeTest extends ValueTypeConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ValueTypesInMemoryTestKernel::class;
    }

    protected function storedSubmission(string $id): Submission
    {
        $provider = static::getContainer()->get('test.submissions_provider');
        self::assertInstanceOf(InMemoryDataProvider::class, $provider);
        $submission = $provider->store()->find($id);
        self::assertInstanceOf(Submission::class, $submission);

        return $submission;
    }
}
