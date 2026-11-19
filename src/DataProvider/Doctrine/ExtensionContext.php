<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiBundle\Action\ActionDescriptor;

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
 *
 * `$action` is the custom action a {@see QueryPurpose::ActionCollection} query is built
 * for, which also carries the request. It is `null` for every other purpose.
 *
 * An extension can run several times inside one query: on the type queried and again in
 * the subquery that scopes each relation a filter, sort or include reaches through. An
 * alias it adds, for a join or a subselect, comes from {@see uniqueAlias()}.
 */
final readonly class ExtensionContext
{
    public function __construct(
        public string $type,
        public QueryPurpose $purpose,
        public ?JsonApiRequestInterface $request = null,
        public ?ActionDescriptor $action = null,
        private ExtensionAliases $aliases = new ExtensionAliases(),
    ) {}

    /**
     * A DQL alias for `$name`, unique within the whole statement however many times
     * extensions run in it: `$name` with a numeric suffix, such as `visible_thing_1`.
     */
    public function uniqueAlias(string $name): string
    {
        return $this->aliases->unique($name);
    }
}
