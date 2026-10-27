<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use haddowg\JsonApi\Scope\ScopeCatalogInterface;

/**
 * Every scope the scopes kernels require, except in the `undeclared` environment, which
 * leaves `comments:read` out so the strict export has something to refuse.
 */
final class ArticleScopeCatalog implements ScopeCatalogInterface
{
    public function __construct(private readonly bool $complete = true) {}

    public function all(): array
    {
        $scopes = [
            'articles:read' => 'Read articles.',
            'articles:write' => 'Change articles.',
            'articles:admin' => 'Delete articles.',
            'articles:publish' => 'Publish articles.',
            'authors:read' => 'Read authors.',
            'comments:read' => 'Read comments.',
            'batch' => 'Send Atomic Operations batches.',
        ];

        if (!$this->complete) {
            unset($scopes['comments:read']);
        }

        return $scopes;
    }
}
