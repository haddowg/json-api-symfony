<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\Field\MorphMany;
use haddowg\JsonApi\Resource\Field\MorphToMany;

abstract class ImUuidOwnerResource extends ImOwnerResource
{
    public static string $type = 'im-uuid-owners';

    protected const string FOREIGN_KEY = 'uuidOwnerId';

    public function fields(): array
    {
        return [
            ...parent::fields(),
            MorphMany::make('refNotes', 'im-notes', 'ref')->withData(),
            MorphToMany::make('links', ['im-uuid-owners'])
                ->through(ImLink::class)
                ->identifiedByRows(owner: 'owner', type: 'kind', id: 'memberKey')
                ->withData(),
        ];
    }
}
