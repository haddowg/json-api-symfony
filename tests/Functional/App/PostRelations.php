<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApiBundle\Attribute\AsJsonApiRelations;
use haddowg\JsonApiBundle\Server\RelationsProviderInterface;

/**
 * Declares the `posts` type's relations with **no** {@see \haddowg\JsonApi\Resource\AbstractResource}
 * (ADR 0026): a to-one `author` and a to-many `comments`, held in the
 * {@see \haddowg\JsonApiBundle\Server\RelationsRegistry} and consumed by the route
 * loader (to emit relationship routes) and the handler (to resolve a relation by
 * name) — exactly as a resource's own relations would be.
 */
#[AsJsonApiRelations(type: 'posts')]
final class PostRelations implements RelationsProviderInterface
{
    public function relations(): array
    {
        return [
            BelongsTo::make('author')->type('authors'),
            HasMany::make('comments')->type('comments'),
        ];
    }
}
