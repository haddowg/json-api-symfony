<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\OpenWidgetEntity;

/**
 * The Doctrine `deprecatedWidgets` the named server serves with its own sunset, as the in-memory {@see \haddowg\JsonApiBundle\Tests\Functional\App\Http\DeprecatedWidgetResource} declares it.
 */
#[AsJsonApiResource(
    entity: OpenWidgetEntity::class,
    server: 'v5',
    deprecation: true,
    sunset: 'Sat, 31 Dec 2050 23:59:59 GMT',
    sunsetLink: 'https://example.test/deprecations/widgets',
)]
final class NamedServerDeprecatedWidgetResource extends AbstractResource
{
    public static string $type = 'deprecatedWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
