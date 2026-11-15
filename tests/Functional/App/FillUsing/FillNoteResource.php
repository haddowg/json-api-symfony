<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;

/**
 * A type whose only relation is written by a fillUsing() hook, to a type no scope
 * hides, so that hook is the only reason a create can name a missing resource.
 */
class FillNoteResource extends AbstractResource
{
    public static string $type = 'fill-notes';

    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('setting', 'fill-settings')
                ->withoutRelatedEndpoint()
                ->extractUsing(fn(mixed $note): ?object => $note instanceof FillNote && $note->settingId !== null
                    ? $this->providers->forType('fill-settings')->fetchOne('fill-settings', $note->settingId)
                    : null)
                ->fillUsing(static function (mixed $note, mixed $linkage): mixed {
                    \assert($note instanceof FillNote && $linkage instanceof ToOneRelationship);
                    $note->settingId = $linkage->resourceIdentifier?->id;

                    return $note;
                }),
        ];
    }
}
