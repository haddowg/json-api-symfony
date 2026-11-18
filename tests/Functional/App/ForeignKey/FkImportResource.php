<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A related-only type no entity maps, holding secrets, a scoped type, under no foreign
 * key. Only the `import` action reads documents of it.
 */
#[AsJsonApiResource(relatedOnly: true)]
final class FkImportResource extends AbstractResource
{
    public static string $type = 'fk-imports';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('secrets', 'fk-secrets')];
    }
}
