<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Catalog;

use haddowg\JsonApi\Catalog\OperationCatalog;
use haddowg\JsonApi\Catalog\OperationSecuritySourceInterface;
use haddowg\JsonApi\Scope\ExactScopeMatcher;
use haddowg\JsonApi\Scope\ScopeChecker;
use haddowg\JsonApi\Scope\ScopeMatcherInterface;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;

/**
 * Builds the catalogue services, which core constructs from a closure and an optional
 * matcher a container cannot pass directly.
 *
 * @internal
 */
final class CatalogFactory
{
    public static function catalog(MetadataSource $metadata, OperationSecuritySourceInterface $security): OperationCatalog
    {
        return new OperationCatalog($metadata->forServer(...), $security);
    }

    public static function checker(?ScopeMatcherInterface $matcher = null): ScopeChecker
    {
        return new ScopeChecker($matcher ?? new ExactScopeMatcher());
    }
}
