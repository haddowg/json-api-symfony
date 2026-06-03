<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests;

use haddowg\JsonApiBundle\JsonApiBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class JsonApiBundleTest extends TestCase
{
    public function testBundleIsAnAbstractBundle(): void
    {
        self::assertInstanceOf(AbstractBundle::class, new JsonApiBundle());
    }
}
