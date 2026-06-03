<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;

/**
 * A factory that seeds an {@see InMemoryDataProvider} with the `articles`
 * fixtures. The seed objects cannot be passed as service-configuration argument
 * literals, so the kernel registers this factory's static method instead.
 */
final class ArticleProviderFactory
{
    public static function create(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('articles', [
            '1' => new Article('1', 'JSON:API in PHP', 'A worked example.'),
            '2' => new Article('2', 'Second article', 'Another one.'),
        ]);
    }
}
