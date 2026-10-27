<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Security;

use haddowg\JsonApi\Scope\ScopeCatalogInterface;

/**
 * Every scope the API defines. The OpenAPI document lists them in the `oauth2` scheme's
 * flows, and the strict export fails on a required scope missing from here.
 */
final class CatalogScopeCatalog implements ScopeCatalogInterface
{
    public function all(): array
    {
        return [
            'catalog:write' => 'Create, change and delete albums and their relationships.',
            'catalog:media' => 'Upload album artwork.',
            'library:write' => 'Create and change playlists and their tracks.',
            'library:admin' => 'Delete playlists.',
            'batch' => 'Send Atomic Operations batches.',
        ];
    }
}
