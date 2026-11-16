<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * A singleton that never says how to find the caller's resource.
 */
final class UnresolvedSettingsResource extends AbstractResource
{
    public static string $type = 'settings';

    public static bool $singleton = true;

    public function fields(): array
    {
        return [Id::make()];
    }
}
