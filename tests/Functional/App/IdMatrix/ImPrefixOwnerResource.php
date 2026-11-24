<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\MorphMany;

abstract class ImPrefixOwnerResource extends ImOwnerResource
{
    public static string $type = 'im-prefix-owners';

    protected const string FOREIGN_KEY = 'prefixOwnerId';

    public function fields(): array
    {
        return [
            ...parent::fields(),
            MorphMany::make('refNotes', 'im-notes', 'ref')->withData(),
        ];
    }

    protected function encoder(): ?IdEncoderInterface
    {
        return new PrefixUuidEncoder();
    }
}
