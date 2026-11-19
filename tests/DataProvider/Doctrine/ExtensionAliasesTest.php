<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider\Doctrine;

use Doctrine\ORM\Query\QueryException;
use haddowg\JsonApiBundle\DataProvider\Doctrine\AppliedExtensions;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionAliases;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtensionAliasesTest extends TestCase
{
    #[Test]
    public function contextsSharingAStatementsAliasesNeverRepeatOne(): void
    {
        $aliases = new ExtensionAliases();
        $root = new ExtensionContext('books', QueryPurpose::FetchCollection, aliases: $aliases);
        $hop = new ExtensionContext('shelves', QueryPurpose::FetchRelatedCollection, aliases: $aliases);

        self::assertSame(['hidden_1', 'hidden_2', 'hidden_1_1', 'other_1'], [
            $root->uniqueAlias('hidden'),
            $hop->uniqueAlias('hidden'),
            $hop->uniqueAlias('hidden_1'),
            $root->uniqueAlias('other'),
        ]);
    }

    #[Test]
    public function aContextBuiltWithoutAliasesStillHandsOutOne(): void
    {
        self::assertSame('hidden_1', (new ExtensionContext('books', QueryPurpose::FetchOne))->uniqueAlias('hidden'));
    }

    #[Test]
    public function aNameThatIsNotAnIdentifierIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ExtensionAliases())->unique('hidden row');
    }

    #[Test]
    public function aDuplicateAliasIsExplainedOnlyAfterAnExtensionRan(): void
    {
        $refused = QueryException::semanticalError("line 0, col 90 near 'hidden_row WHERE': Error: 'hidden_row' is already defined.");
        $applied = new AppliedExtensions();

        self::assertNull($applied->explain($refused));

        $extension = $this->createStub(DoctrineExtensionInterface::class);
        $applied->record([$extension]);
        $explained = $applied->explain(new \RuntimeException('wrapped', 0, $refused));

        self::assertNotNull($explained);
        self::assertStringContainsString('"hidden_row" is defined twice', $explained->getMessage());
        self::assertStringContainsString($extension::class, $explained->getMessage());
        self::assertNull($applied->explain(new \RuntimeException('unrelated')));

        $applied->reset();
        self::assertNull($applied->explain($refused));
    }
}
