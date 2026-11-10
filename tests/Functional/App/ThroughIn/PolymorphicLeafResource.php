<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A filter whose path ends on a polymorphic relation, which has no single id to
 * compare, so the warm-up a cold boot runs refuses it.
 */
#[AsJsonApiResource(readOnly: true)]
final class PolymorphicLeafResource extends AbstractResource
{
    public static string $type = 'wtiMentions';

    public function fields(): array
    {
        return [
            Id::make(),
            MorphTo::make('subject', ['wtiAdvertisers', 'wtiTemplates']),
        ];
    }

    public function filters(): array
    {
        return [
            WhereThroughIn::make('subject'),
        ];
    }
}
