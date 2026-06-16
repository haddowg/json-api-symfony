<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Http;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The deprecation/sunset witness (bundle ADR 0054, gap G16): a bare
 * `Deprecation` header plus a `Sunset` HTTP-date and a companion sunset `Link` —
 * emitted on every response for the type (reads and writes), not just GETs.
 */
#[AsJsonApiResource(
    deprecation: true,
    sunset: 'Sat, 31 Dec 2050 23:59:59 GMT',
    sunsetLink: 'https://example.test/deprecations/widgets',
)]
final class DeprecatedWidgetResource extends AbstractResource
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
