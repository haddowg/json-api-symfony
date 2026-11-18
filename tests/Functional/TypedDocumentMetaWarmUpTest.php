<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Resource\DocumentMetaMisdeclared;
use haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta\TypedMetaClashKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A collection's declared meta naming a member core writes fails warm-up.
 */
final class TypedDocumentMetaWarmUpTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return TypedMetaClashKernel::class;
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
    public function aCollectionMemberCoreWritesFailsWarmUp(): void
    {
        (new Filesystem())->remove((new TypedMetaClashKernel('test', false))->getCacheDir());

        $this->expectException(DocumentMetaMisdeclared::class);
        $this->expectExceptionMessage('The top-level meta of the "tm-clashes" FetchCollection document declares the member "page", which core writes into every collection document.');

        static::bootKernel(['debug' => false]);
    }
}
