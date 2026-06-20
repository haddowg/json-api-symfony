<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `stickers` declaration both strict-sparse-fieldset kernels serve — the
 * related type a leaflet's to-one `sticker` relationship links to. Minimal: an `id`
 * and a single `label` attribute, so a `fields[stickers]` member is either `label`
 * (or `id`) — tolerated — or unknown, and the strict member check 400s the unknown
 * one for the INCLUDED type exactly as for the primary type.
 */
abstract class BaseStickerResource extends AbstractResource
{
    public static string $type = 'stickers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
        ];
    }
}
