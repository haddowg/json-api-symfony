<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\PrefixIdEncoder;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine\TemplateEntity;

/**
 * Templates whose integer key is served as `tpl-<id>`, so a filter ending on one
 * decodes each wire id before comparing it.
 */
abstract class BaseTemplateResource extends AbstractResource
{
    public static string $type = 'wtiTemplates';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixIdEncoder('tpl-')),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof TemplateEntity ? static::$type : '';
    }
}
