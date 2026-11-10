<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

/**
 * A binding is hidden from every read by the kernel's scope when `hidden`, and by the
 * type's default filter when `detached`.
 */
abstract class BaseBindingResource extends AbstractResource
{
    public static string $type = 'bindings';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('detached')->asBoolean()->default(false),
        ];
    }
}
