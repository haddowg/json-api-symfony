<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\IdMatrixBareMorphIdKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A relation reading through the bare id field of a MorphTo identified by columns would
 * read the rows of every type sharing an id, so warm-up refuses it and names the MorphTo.
 */
final class IdMatrixWarmUpTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return IdMatrixBareMorphIdKernel::class;
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
    public function aForeignKeyNamingAMorphTosIdFieldFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "notes" on resource type "im-posts" reads through the foreign key "subjectId" on type "im-notes", but it is a field the polymorphic relation "subject" stores its target in');

        static::bootKernel(['debug' => false]);
    }
}
