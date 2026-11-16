<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The `inbox-items` type, an ordinary read-only type whose `inbox` relation reaches the
 * singleton.
 */
abstract class InboxItemResource extends AbstractResource
{
    public static string $type = 'inbox-items';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('subject'),
            BelongsTo::make('inbox', 'inboxes'),
        ];
    }
}
