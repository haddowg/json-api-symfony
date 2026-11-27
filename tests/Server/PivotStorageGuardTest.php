<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Server\PivotStorageGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation with stored pivot fields needs its related type's provider to read pivot rows,
 * so the cache warm-up refuses one served by a provider that can't.
 */
#[CoversClass(PivotStorageGuard::class)]
final class PivotStorageGuardTest extends TestCase
{
    #[Test]
    public function storedPivotFieldsServedByAProviderThatReadsNoPivotRowsFailWarmUp(): void
    {
        $provider = $this->createStub(DataProviderInterface::class);
        $provider->method('supports')->willReturn(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "slots" on resource type "pivot-storage-shelves" declares stored pivot fields, but ' . $provider::class . ', the data provider of its related type "pivot-storage-items", reads no pivot rows. Implement haddowg\JsonApiBundle\DataProvider\PivotAwareProviderInterface on it');

        PivotStorageGuard::check(new DataProviderRegistry([$provider]), 'pivot-storage-shelves', new PivotStorageShelfResource());
    }

    #[Test]
    public function storedPivotFieldsServedByAProviderThatReadsPivotRowsBootClean(): void
    {
        PivotStorageGuard::check(new DataProviderRegistry([new InMemoryDataProvider('pivot-storage-items', [])]), 'pivot-storage-shelves', new PivotStorageShelfResource());
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function computedPivotFieldsNeedNoProviderThatReadsPivotRows(): void
    {
        $provider = $this->createStub(DataProviderInterface::class);
        $provider->method('supports')->willReturn(true);

        PivotStorageGuard::check(new DataProviderRegistry([$provider]), 'pivot-storage-shelves', new PivotStorageComputedShelfResource());
        $this->addToAssertionCount(1);
    }
}

/**
 * @internal
 */
final class PivotStorageShelfResource extends AbstractResource
{
    public static string $type = 'pivot-storage-shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('slots', 'pivot-storage-items')->fields(Integer::make('position')),
        ];
    }
}

/**
 * @internal
 */
final class PivotStorageComputedShelfResource extends AbstractResource
{
    public static string $type = 'pivot-storage-shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('slots', 'pivot-storage-items')->fields(Str::make('label')->computedUsing(static fn(): string => 'x')),
        ];
    }
}
