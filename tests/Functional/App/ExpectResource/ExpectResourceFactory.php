<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * The in-memory provider for the unmapped `memos` type of {@see ExpectResourceTestKernel}.
 */
final class ExpectResourceFactory
{
    public static function memos(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('memos', ['4' => new Memo(4, 'Remember')]);
    }
}
