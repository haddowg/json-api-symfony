<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenApi;

use haddowg\JsonApi\Resource\Enum\DescribedEnum;
use haddowg\JsonApi\Resource\Enum\DescribesEnumCases;
use haddowg\JsonApi\Resource\Enum\EnumCaseDescription;

/**
 * A backed status enum for the OpenAPI document witness: an `In`-constrained
 * attribute sourced from a backed enum becomes a **named, reusable component**
 * (`#/components/schemas/CatalogStatus`) with per-value descriptions (design §4.8,
 * D16). It opts into the per-value descriptions via core's
 * {@see DescribedEnum} + {@see DescribesEnumCases}.
 */
enum CatalogStatus: string implements DescribedEnum
{
    use DescribesEnumCases;

    #[EnumCaseDescription('Not yet visible in the catalog')]
    case Draft = 'draft';

    #[EnumCaseDescription('Live and listed')]
    case Published = 'published';

    #[EnumCaseDescription('Withdrawn from sale')]
    case Archived = 'archived';
}
