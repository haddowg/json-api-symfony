<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Refuses a single read of an article named `restricted`.
 */
#[AsJsonApiResource(readOnly: true, securityRead: "object.name != 'restricted'")]
class GatedArticleResource extends AbstractResource
{
    public static string $type = 'gatedArticles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
