<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The `fk-inboxes` singleton at `/inbox`, Ada's, served by {@see FkUserProvider}, whose
 * items read through their owner.
 */
#[AsJsonApiResource(readOnly: true)]
final class FkInboxResource extends AbstractResource
{
    public static string $type = 'fk-inboxes';

    public static string $uriType = 'inbox';

    public static bool $singleton = true;

    public function resolveSingletonId(SingletonContext $context): string
    {
        return 'ada';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('items', 'fk-items', 'owner')->paginate(PagePaginator::make()->withDefaultPerPage(2)),
        ];
    }
}
