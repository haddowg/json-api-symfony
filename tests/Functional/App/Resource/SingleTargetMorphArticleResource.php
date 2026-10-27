<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;

/**
 * The shared `articles` declaration for the single-target polymorphic suite: the
 * {@see BaseArticleResource} vocabulary plus a `MorphTo` and a `MorphToMany` over one
 * type each. `writer` reads the same column as the monomorphic `author`, and `remarks`
 * the same as `comments`, so each pair differs only in being polymorphic.
 *
 * Not `final` so the Doctrine variant inherits the same declarations.
 */
class SingleTargetMorphArticleResource extends BaseArticleResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            MorphTo::make('writer', ['authors'])->storedAs('author'),
            MorphToMany::make('remarks', ['comments'])->storedAs('comments')->countable(),
        ];
    }
}
