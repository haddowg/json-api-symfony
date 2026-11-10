<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A template whose `summary` attribute iterates its bindings, so on Doctrine the
 * association is initialised before the relationships render. `bindings` and
 * `sharedBindings` are lazy, `listedBindings` always emits its linkage data.
 */
abstract class BaseTemplateResource extends AbstractResource
{
    public static string $type = 'templates';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('summary')->readOnly()->extractUsing(
                static fn(mixed $template): ?string => $template instanceof LinkageTemplate
                    ? \sprintf('%d bindings, %d shared', \count($template->getBindings()->toArray()), \count($template->getSharedBindings()->toArray()))
                    : null,
            ),
            HasMany::make('bindings', 'bindings')->readOnly()->countable(),
            HasMany::make('listedBindings', 'bindings')->storedAs('bindings')->readOnly()->withData(),
            BelongsToMany::make('sharedBindings', 'bindings')->readOnly(),
        ];
    }
}
