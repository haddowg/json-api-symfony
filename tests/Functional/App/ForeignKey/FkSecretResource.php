<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * `fk-secrets`, which the Doctrine kernel's {@see FkSecretExtension} scopes to the rows not hidden.
 */
abstract class FkSecretResource extends AbstractResource
{
    public static string $type = 'fk-secrets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            Str::make('owner'),
        ];
    }
}
