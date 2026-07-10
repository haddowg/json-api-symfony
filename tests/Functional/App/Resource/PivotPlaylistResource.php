<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The in-memory `playlists` resource for the pivot boundary witness. It declares
 * the SAME `tracks` {@see BelongsToMany} with pivot fields the Doctrine fixture
 * does — but the in-memory provider is not pivot-aware, so the pivot vocabulary is
 * NOT merged on its related endpoint: a `?filter[position]`/`?sort=position` key is
 * unrecognised (400) and no pivot meta renders. The `tracks` read off the parent's
 * `tracks` property exactly as a plain to-many.
 *
 * It also declares a `dataTracks` pivot relation that renders its linkage data BY
 * DEFAULT (`withData()`, reading the same `tracks` property) — the in-memory
 * boundary witness for a PRIMARY document (bundle ADR 0102): because the in-memory
 * provider is not pivot-aware, the default-rendered linkage carries NO `meta.pivot`,
 * exactly as the related/relationship endpoints carry none here.
 */
final class PivotPlaylistResource extends AbstractResource
{
    public static string $type = 'playlists';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('name'),
            BelongsToMany::make('tracks', 'tracks')
                ->fields(Integer::make('position')->build()),
            BelongsToMany::make('dataTracks', 'tracks')
                ->storedAs('tracks')
                ->fields(Integer::make('position')->build())
                ->withData(),
        ];
    }
}
