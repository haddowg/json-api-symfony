<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * The `catalog-exports` type — the **always-async create** witness. A `POST
 * /catalog-exports` is never committed inline: the paired
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\CatalogExportPersister}
 * returns an {@see \haddowg\JsonApiBundle\DataPersister\AcceptedForProcessing}, so the
 * only success the endpoint advertises is `create: [new Accepted('export-jobs')]` — a
 * `202` with the pollable `export-jobs` job document, `Content-Location`, and
 * `Retry-After`. `delete: [new NoContent(), new MetaResult()]` documents both spec-valid
 * delete successes (`204` and a `200` meta-only document). No Doctrine entity — like
 * `charts`/`countries` it is served by a small custom provider + persister.
 */
#[AsJsonApiResource(
    operations: [Operation::Create, Operation::FetchOne, Operation::FetchCollection, Operation::Delete],
    create: [new Accepted('export-jobs')],
    delete: [new NoContent(), new MetaResult()],
)]
final class CatalogExportResource extends AbstractResource
{
    public static string $type = 'catalog-exports';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('format')->required()->maxLength(16),
            Str::make('status')->readOnly(),
        ];
    }
}
