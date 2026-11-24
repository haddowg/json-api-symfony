<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * Every task has an owner, and a new task names at least one watcher.
 */
class TaskResource extends AbstractResource
{
    public static string $type = 'tasks';

    public function fields(): array
    {
        return [
            Id::make()->allowClientId(),
            Str::make('title'),
            BelongsTo::make('owner', 'members')->required(),
            BelongsToMany::make('watchers', 'members')->requiredOnCreate(),
        ];
    }
}
