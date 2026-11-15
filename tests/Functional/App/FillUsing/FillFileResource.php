<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;

/**
 * Every relation is written by its own fillUsing() hook: `target` into two columns,
 * `watchers` and `settings` into a list of ids, honouring the endpoint's mode, and
 * `setting` into one id. The settings are served by a custom provider.
 */
class FillFileResource extends AbstractResource
{
    public static string $type = 'fill-files';

    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphTo::make('target', ['fill-agencies', 'fill-members'])
                ->extractUsing(fn(mixed $file): ?object => $file instanceof FillFile && $file->targetType !== null && $file->targetId !== null
                    ? $this->providers->forType($file->targetType)->fetchOne($file->targetType, $file->targetId)
                    : null)
                ->fillUsing(static function (mixed $file, mixed $linkage): mixed {
                    \assert($file instanceof FillFile && $linkage instanceof ToOneRelationship);
                    $file->targetType = $linkage->resourceIdentifier?->type;
                    $file->targetId = $linkage->resourceIdentifier?->id;

                    return $file;
                }),
            HasMany::make('watchers', 'fill-members')
                ->withData()
                ->extractUsing(fn(mixed $file): array => $file instanceof FillFile
                    ? \array_values(\array_filter(\array_map(fn(string $id): ?object => $this->providers->forType('fill-members')->fetchOne('fill-members', $id), $file->watcherIds)))
                    : [])
                ->fillUsing(static function (mixed $file, mixed $linkage, array $data): mixed {
                    \assert($file instanceof FillFile && $linkage instanceof ToManyRelationship);
                    $ids = \array_values(\array_filter(\array_map(static fn($identifier): ?string => $identifier->id, $linkage->resourceIdentifiers)));
                    $file->watcherIds = match ($data['mode'] ?? Mode::Replace) {
                        Mode::Add => \array_values(\array_unique([...$file->watcherIds, ...$ids])),
                        Mode::Remove => \array_values(\array_diff($file->watcherIds, $ids)),
                        default => $ids,
                    };

                    return $file;
                }),
            BelongsTo::make('setting', 'fill-settings')
                ->withoutRelatedEndpoint()
                ->extractUsing(fn(mixed $file): ?object => $file instanceof FillFile && $file->settingId !== null
                    ? $this->providers->forType('fill-settings')->fetchOne('fill-settings', $file->settingId)
                    : null)
                ->fillUsing(static function (mixed $file, mixed $linkage): mixed {
                    \assert($file instanceof FillFile && $linkage instanceof ToOneRelationship);
                    $file->settingId = $linkage->resourceIdentifier?->id;

                    return $file;
                }),
            HasMany::make('settings', 'fill-settings')
                ->withoutRelatedEndpoint()
                ->withData()
                ->extractUsing(fn(mixed $file): array => $file instanceof FillFile
                    ? \array_values(\array_filter(\array_map(fn(string $id): ?object => $this->providers->forType('fill-settings')->fetchOne('fill-settings', $id), $file->settingIds)))
                    : [])
                ->fillUsing(static function (mixed $file, mixed $linkage, array $data): mixed {
                    \assert($file instanceof FillFile && $linkage instanceof ToManyRelationship);
                    $ids = \array_values(\array_filter(\array_map(static fn($identifier): ?string => $identifier->id, $linkage->resourceIdentifiers)));
                    $file->settingIds = match ($data['mode'] ?? Mode::Replace) {
                        Mode::Add => \array_values(\array_unique([...$file->settingIds, ...$ids])),
                        Mode::Remove => \array_values(\array_diff($file->settingIds, $ids)),
                        default => $ids,
                    };

                    return $file;
                }),
        ];
    }
}
