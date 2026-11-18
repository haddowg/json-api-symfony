<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\ComputedItemsUserResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\ComputedSecretsUserResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\ForeignKeyGuardKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\KeyedItemsUserResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\MisnamedKeyUserResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\UnkeyedItemsUserResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\UnkeyedProfileUserResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard\UnkeyedSecretsUserResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A to-many from a type no entity maps into a Doctrine type a query extension scopes has
 * no way to scope those reads to its parent, so warm-up refuses one that names no foreign
 * key and isn't computed. Into an unscoped type, and for a to-one, the model's value is
 * read, so it boots. A foreign key that is named is checked.
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
    public function aToManyIntoAScopedTypeWithNoForeignKeyFailsWarmUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Relation "secrets" on resource type "fk-users" reads type "fk-secrets", which a Doctrine query extension scopes.*foreign key argument, HasMany::make\\(\'secrets\', \'fk-secrets\', \'<foreign key>\'\\), or compute it with extractUsing\\(\\)/s');

        $this->bootWithSubject(UnkeyedSecretsUserResource::class);
    }

    #[Test]
    public function aToManyIntoAnUnscopedTypeWithNoForeignKeyBootsClean(): void
    {
        $this->bootWithSubject(UnkeyedItemsUserResource::class);
        self::assertNotNull(static::$kernel);
    }

    #[Test]
    public function aHasOneWithNoForeignKeyBootsClean(): void
    {
        $this->bootWithSubject(UnkeyedProfileUserResource::class);
        self::assertNotNull(static::$kernel);
    }

    #[Test]
    public function aComputedRelationIntoAScopedTypeBootsClean(): void
    {
        $this->bootWithSubject(ComputedSecretsUserResource::class);
        self::assertNotNull(static::$kernel);
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
