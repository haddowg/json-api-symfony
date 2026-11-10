<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

use haddowg\JsonApi\Resource\Field\MorphToManyBuilder;

/**
 * The identified `companies` relation with its members fixed: read from stored pairs and
 * written by fillUsing(), so the bundle's own membership check is the only one that runs.
 */
final class FixedMtmUserResource extends MtmUserResource
{
    protected function companies(): MorphToManyBuilder
    {
        return parent::companies()->cannotChangeMembership();
    }
}
