<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\ComputedItemsUserResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\ForeignKeyGuardKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\KeyedItemsUserResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\MisnamedKeyUserResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\UnkeyedItemsUserResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\UnkeyedProfileUserResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A relation from a type no entity maps into a Doctrine type has no association to read,
 * so warm-up refuses one that names no foreign key and isn't computed, and checks a
 * foreign key it does name.
 */
final class ForeignKeyWarmUpTest extends KernelTestCase
{
    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected static function getKernelClass(): string
    {
        return ForeignKeyGuardKernel::class;
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
        $this->restoreHandlers();
    }

    #[Test]
    public function aToManyWithNoForeignKeyFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Relation "items" on resource type "fk-users".*not a Doctrine entity.*foreign key argument, HasMany::make\(\'items\', \'fk-items\', \'<foreign key>\'\), or compute it with extractUsing\(\)/s');

        $this->bootWithSubject(UnkeyedItemsUserResource::class);
    }

    #[Test]
    public function aHasOneWithNoForeignKeyFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Relation "profile".*HasOne::make\(\'profile\', \'fk-profiles\', \'<foreign key>\'\)/s');

        $this->bootWithSubject(UnkeyedProfileUserResource::class);
    }

    #[Test]
    public function aMisnamedForeignKeyFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('reads through the foreign key "missing" on type "fk-items", but it declares no such field or relation');

        $this->bootWithSubject(MisnamedKeyUserResource::class);
    }

    #[Test]
    public function aComputedRelationBootsClean(): void
    {
        $this->bootWithSubject(ComputedItemsUserResource::class);
        self::assertNotNull(static::$kernel);
    }

    #[Test]
    public function relationsWithForeignKeysBootClean(): void
    {
        $this->bootWithSubject(KeyedItemsUserResource::class);
        self::assertNotNull(static::$kernel);
    }

    /**
     * @param class-string $subject
     */
    private function bootWithSubject(string $subject): void
    {
        static::ensureKernelShutdown();
        ForeignKeyGuardKernel::$subjectResource = $subject;
        static::bootKernel(['debug' => false]);
    }

    private function restoreHandlers(): void
    {
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
}
