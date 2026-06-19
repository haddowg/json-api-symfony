<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Model;

use haddowg\JsonApi\Resource\Enum\DescribedEnum;
use haddowg\JsonApi\Resource\Enum\DescribesEnumCases;
use haddowg\JsonApi\Resource\Enum\EnumCaseDescription;

/**
 * The release lifecycle of an album — a **backed enum** wired onto the `albums`
 * resource's `status` attribute via `->enum(AlbumStatus::class)`. The OpenAPI
 * projection hoists it into a reusable named component
 * (`#/components/schemas/AlbumStatus`) and emits its per-value descriptions (design
 * §4.8/D16), so the generated docs explain each case — the example app's witness for
 * the enum-description feature.
 *
 * It opts into per-value descriptions through core's {@see DescribedEnum} contract +
 * {@see DescribesEnumCases} reflection trait, declaring each case's description with
 * {@see EnumCaseDescription}.
 */
enum AlbumStatus: string implements DescribedEnum
{
    use DescribesEnumCases;

    #[EnumCaseDescription('Announced but not yet on sale.')]
    case Upcoming = 'upcoming';

    #[EnumCaseDescription('Released and available to stream or buy.')]
    case Released = 'released';

    #[EnumCaseDescription('Withdrawn from the catalogue (back-catalogue or rights lapsed).')]
    case Withdrawn = 'withdrawn';
}
