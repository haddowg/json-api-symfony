<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaContext;

/**
 * Items keep any integer meta member they are written with, and render them back.
 */
abstract class TrItemResource extends AbstractResource
{
    public static string $type = 'tr-items';

    public function getType(mixed $object): string
    {
        return $object instanceof TrItem ? static::$type : '';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }

    public function resourceMeta(MetaContext $context): iterable
    {
        return [
            AdditionalProperties::make()->of(Integer::make('*'))
                ->extractUsing(static fn(TrItem $item): array => $item->extras)
                ->fillUsing(static function (TrItem $item, array $members): void {
                    $item->extras = $members;
                }),
        ];
    }
}
