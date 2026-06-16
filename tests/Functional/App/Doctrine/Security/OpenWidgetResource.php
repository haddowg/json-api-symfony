<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The `openWidgets` resource: declares **no** security, so the authorization layer
 * never gates it — the witness that a resource without `security` is unaffected.
 */
#[AsJsonApiResource(entity: OpenWidgetEntity::class)]
final class OpenWidgetResource extends AbstractResource
{
    public static string $type = 'openWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
