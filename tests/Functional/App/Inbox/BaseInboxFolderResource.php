<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\InboxFolderEntity;

/**
 * Folders of inbox items: `items` keeps the items' default, `everything` drops it.
 */
abstract class BaseInboxFolderResource extends AbstractResource
{
    public static string $type = 'inbFolders';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
            HasMany::make('items', 'inbItems'),
            HasMany::make('everything', 'inbItems')->storedAs('items')->withoutFilterDefaults(),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof InboxFolderEntity ? static::$type : '';
    }
}
