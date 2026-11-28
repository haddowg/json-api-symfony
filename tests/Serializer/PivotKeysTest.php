<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Serializer;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApiBundle\Serializer\PivotKeys;
use haddowg\JsonApiBundle\Serializer\PivotMapBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A provider may key its pivot map by the wire id or by the id it stores, so the lookup
 * finds an entry under the wire id, its comparable form, or the id the encoder decodes it to.
 */
#[CoversClass(PivotKeys::class)]
#[CoversClass(PivotMapBatch::class)]
final class PivotKeysTest extends TestCase
{
    #[Test]
    public function aMapKeyedByTheStoredIdIsFoundThroughTheEncoder(): void
    {
        $encoder = new class implements IdEncoderInterface {
            public function encode(mixed $storageKey): string
            {
                return 'player-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
            }

            public function decode(string $wireId): ?string
            {
                return \str_starts_with($wireId, 'player-') ? \substr($wireId, 7) : null;
            }
        };

        self::assertSame(['shirt' => 7], PivotKeys::find(['1' => ['shirt' => 7]], 'player-1', $encoder));
        self::assertSame(['shirt' => 7], PivotKeys::find(['player-1' => ['shirt' => 7]], 'player-1', $encoder));
        self::assertNull(PivotKeys::find(['1' => ['shirt' => 7]], 'player-1', null));

        $batch = new PivotMapBatch(static fn(array $parents): array => ['1' => ['1' => ['shirt' => 7]]], [new \stdClass()], 'App\\Provider', $encoder);
        self::assertSame(['1' => ['shirt' => 7]], $batch->forParent('player-1'));
    }

    #[Test]
    public function aUuidKeyedInCapitalsIsFoundInEitherCase(): void
    {
        self::assertSame(['shirt' => 7], PivotKeys::find(['A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C01' => ['shirt' => 7]], 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c01', null));
    }
}
