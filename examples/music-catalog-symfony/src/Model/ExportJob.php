<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Model;

/**
 * The pollable job resource for an async {@see CatalogExport}. Its fetch-one is the
 * JSON:API asynchronous-processing completion endpoint: while `state` is `processing`
 * it renders a normal `200` status document; once `completed` the resource implements
 * {@see \haddowg\JsonApi\Resource\ResolvesCompletionRedirect} to answer `303 See Other`
 * pointing at `$exportId`'s produced `catalog-exports` resource. `$exportId` is internal
 * (drives the redirect) and is deliberately NOT a declared JSON:API field.
 */
final class ExportJob
{
    public function __construct(
        public string $id = '',
        public string $state = '',
        public string $exportId = '',
    ) {}
}
