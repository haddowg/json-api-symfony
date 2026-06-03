<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The `articles` resource fixture (mirrors core's getting-started example). It
 * declares an Id plus two string attributes, so the serializer renders the spec
 * `attributes` object. It is autoconfigured to the resource tag by the test
 * kernel.
 */
final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('body'),
        ];
    }
}
