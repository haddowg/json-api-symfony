<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApiBundle\Attribute\AsJsonApiRelations;
use haddowg\JsonApiBundle\Server\RelationsProviderInterface;

/**
 * The default server's `notes` relations: an `author` only an admin may read.
 */
#[AsJsonApiRelations(type: 'notes', server: 'default')]
final class DefaultServerNoteRelations implements RelationsProviderInterface
{
    public function relations(): array
    {
        return [
            BelongsTo::make('author', 'notes')->security(read: "is_granted('ROLE_ADMIN')")->build(),
        ];
    }
}
