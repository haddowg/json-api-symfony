<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory kernel's `articles` resource: the shared declaration served by
 * the {@see \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider}. It is
 * autoconfigured to the resource tag by the test kernel.
 */
final class ArticleResource extends BaseArticleResource {}
