<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The one source of the `articles` seed data: the in-memory provider and the
 * Doctrine test database are seeded from the same map, so both functional
 * suites assert against identical content.
 *
 * Titles are distinct and start with distinct uppercase ASCII, so byte-order
 * sorting is unambiguous; `category` deliberately carries ties (guide × 3,
 * news × 2) so multi-field sort composition is observable.
 */
final class ArticleFixtures
{
    /**
     * Keyed by article id. PHP coerces the numeric-string keys to `int`, so
     * consumers cast back to `string` at the use site.
     *
     * @return array<int|string, array{title: string, body: string, category: string}>
     */
    public static function data(): array
    {
        return [
            '1' => ['title' => 'JSON:API in PHP', 'body' => 'A worked example.', 'category' => 'guide'],
            '2' => ['title' => 'Second article', 'body' => 'Another one.', 'category' => 'guide'],
            '3' => ['title' => 'Building bundles', 'body' => 'Symfony integration.', 'category' => 'news'],
            '4' => ['title' => 'Zebra patterns', 'body' => 'Stripes, mostly.', 'category' => 'guide'],
            '5' => ['title' => 'Async pipelines', 'body' => 'Queues and workers.', 'category' => 'news'],
        ];
    }
}
