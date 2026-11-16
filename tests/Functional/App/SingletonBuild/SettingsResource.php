<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SingletonBuild;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\SingletonContext;

/**
 * A well-formed singleton the build tests declare variants of.
 */
abstract class SettingsResource extends AbstractResource
{
    public static string $type = 'settings';

    public static bool $singleton = true;

    public function resolveSingletonId(SingletonContext $context): ?string
    {
        return '1';
    }

    public function fields(): array
    {
        return [Id::make(), Str::make('locale')];
    }
}
