<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta\TypedRequestMetaMisdeclaredKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A misdeclared request or resource object meta fails warm-up.
 */
final class TypedRequestMetaWarmUpTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return TypedRequestMetaMisdeclaredKernel::class;
    }

    protected function setUp(): void
    {
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();

        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->errorHandler) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->exceptionHandler) {
                break;
            }
            \restore_exception_handler();
        }
    }

    #[Test]
    public function getMetaBesideResourceMetaFailsWarmUp(): void
    {
        $this->assertWarmUpFails('getmeta', 'declares resourceMeta() and overrides the deprecated getMeta()');
    }

    #[Test]
    public function aMemberDeclaredTwiceFailsWarmUp(): void
    {
        $this->assertWarmUpFails('twice', 'declares the member "reason" twice');
    }

    #[Test]
    public function twoAdditionalPropertiesEntriesFailWarmUp(): void
    {
        $this->assertWarmUpFails('entries', 'more than one additionalProperties entry');
    }

    #[Test]
    public function requestMetaOnARawBodyFailsWarmUp(): void
    {
        $this->assertWarmUpFails('raw', 'takes a raw body but declares request meta');
    }

    private function assertWarmUpFails(string $environment, string $message): void
    {
        (new Filesystem())->remove((new TypedRequestMetaMisdeclaredKernel($environment, false))->getCacheDir());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        static::bootKernel(['environment' => $environment, 'debug' => false]);
    }

    #[Test]
    public function theWellDeclaredAppWarmsUp(): void
    {
        (new Filesystem())->remove((new TypedRequestMetaMisdeclaredKernel('test', false))->getCacheDir());

        self::assertTrue(static::bootKernel(['environment' => 'test', 'debug' => false])->getContainer()->has('kernel'));
    }
}
