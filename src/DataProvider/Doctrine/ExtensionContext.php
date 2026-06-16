<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The context a {@see DoctrineExtensionInterface} receives on every
 * {@see DoctrineExtensionInterface::apply()} call: the resource `$type` being
 * scoped, the {@see QueryPurpose} (why the query is built), and the parsed
 * {@see JsonApiRequestInterface} the operation was dispatched for — or `null`
 * where the call site has no request to thread.
 *
 * `$request` is the seam for **request-aware** scoping (read a query parameter
 * or header off the JSON:API request and branch on it). It is populated on the
 * related/include/batch loads — each of those provider methods receives the
 * request on its SPI signature — and is `null` on the primary
 * {@see QueryPurpose::FetchOne}/{@see QueryPurpose::FetchCollection} loads, whose
 * SPI does not carry one (the primary-vs-related distinction is instead carried
 * by {@see QueryPurpose::FetchRelatedCollection}). An extension that branches on
 * `$request` must therefore tolerate `null` (fall through to its unconditional
 * base scope) so a primary fetch is still scoped.
 */
final readonly class ExtensionContext
{
    public function __construct(
        public string $type,
        public QueryPurpose $purpose,
        public ?JsonApiRequestInterface $request = null,
    ) {}
}
