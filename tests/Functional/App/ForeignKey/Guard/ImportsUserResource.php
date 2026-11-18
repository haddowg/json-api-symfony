<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey\Guard;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A provider-backed user whose last import, an `fk-imports`, renders as a related
 * resource, so the import's own relations are read.
 */
#[AsJsonApiResource(readOnly: true)]
final class ImportsUserResource extends AbstractResource
{
    public static string $type = 'fk-users';

    public function fields(): array
    {
        return [Id::make(), BelongsTo::make('lastImport', 'fk-imports')];
    }
}
