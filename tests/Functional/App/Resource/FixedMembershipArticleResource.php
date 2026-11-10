<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\HasMany;

/**
 * The shared `articles` declaration for the fixed membership suite: the
 * {@see BaseArticleResource} vocabulary plus two relations over the `comments` column
 * declared `cannotChangeMembership()`, one unconditionally and one only for a caller
 * that is not an admin. The base `comments` stays the unflagged witness.
 *
 * Not `final` so the Doctrine variant inherits the same declarations.
 */
class FixedMembershipArticleResource extends BaseArticleResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            HasMany::make('fixedComments', 'comments')->storedAs('comments')->cannotChangeMembership(),
            HasMany::make('gatedComments', 'comments')->storedAs('comments')->cannotChangeMembership(
                static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('X-Role') !== 'admin',
            ),
        ];
    }
}
