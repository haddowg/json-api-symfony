<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DecimalColumn;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * `prices` is a MorphToMany read from the tray's link rows and filled by a hook, so the
 * bundle writes each member's `discount` pivot value onto the row the hook leaves.
 */
#[AsJsonApiResource(entity: TrayEntity::class)]
final class TrayResource extends AbstractResource
{
    public static string $type = 'trays';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphToMany::make('prices', ['prices'])
                ->identifiedBy(static fn(mixed $tray): array => $tray instanceof TrayEntity
                    ? \array_values(\array_map(static fn(TrayLineEntity $row): array => [$row->itemType, $row->itemId, $row], $tray->lines->toArray()))
                    : [])
                ->fields(Decimal::make('discount')->nullable())
                ->fillUsing(self::fillLines(...)),
        ];
    }

    /**
     * Adds and removes the link rows the linkage names.
     *
     * @param array<string, mixed> $data
     */
    private static function fillLines(mixed $tray, mixed $linkage, array $data): mixed
    {
        \assert($tray instanceof TrayEntity && $linkage instanceof ToManyRelationship);
        $sent = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            $sent[$identifier->type . ':' . $identifier->id] = [$identifier->type, (int) $identifier->id];
        }

        $mode = $data['mode'] ?? Mode::Replace;
        foreach ($tray->lines->toArray() as $row) {
            $named = isset($sent[$row->key()]);
            if (($mode === Mode::Replace && !$named) || ($mode === Mode::Remove && $named)) {
                $tray->lines->removeElement($row);
            }
            unset($sent[$row->key()]);
        }

        if ($mode !== Mode::Remove) {
            foreach ($sent as [$type, $id]) {
                $tray->lines->add(new TrayLineEntity(tray: $tray, itemType: $type, itemId: $id));
            }
        }

        return $tray;
    }
}
