<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\PrefixIdEncoder;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine\ThrTemplateEntity;

/**
 * Templates whose integer key is served as `tpl-<id>`.
 */
abstract class BaseThrTemplateResource extends AbstractResource
{
    public static string $type = 'thrTemplates';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixIdEncoder('tpl-')),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof ThrTemplateEntity ? static::$type : '';
    }
}
