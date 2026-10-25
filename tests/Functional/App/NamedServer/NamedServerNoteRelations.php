<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApiBundle\Attribute\AsJsonApiRelations;
use haddowg\JsonApiBundle\Server\RelationsProviderInterface;

/**
 * The named server's `notes` relations: an ungated `author` and an `editor` the default
 * server does not have.
 */
#[AsJsonApiRelations(type: 'notes', server: 'v5')]
final class NamedServerNoteRelations implements RelationsProviderInterface
{
    public function relations(): array
    {
        return [
            BelongsTo::make('author', 'notes')->build(),
            BelongsTo::make('editor', 'notes')->build(),
        ];
    }
}
