<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Provider;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataPersister\AcceptedForProcessing;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\CatalogExport;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\ExportJob;

/**
 * The write half of the `catalog-exports` type: {@see create()} accepts the export for
 * asynchronous processing instead of committing it, returning an
 * {@see AcceptedForProcessing} pointing at a pollable `export-jobs` resource — so the
 * handler renders the `202 Accepted` (`Content-Location` + `Retry-After`) the resource's
 * `create: [new Accepted('export-jobs')]` declaration advertises. A real persister would
 * dispatch a queued job here; this witness just mints the `processing` job. {@see delete()}
 * is a no-op (`204`); this type declares no `update`, so {@see update()} is unreachable.
 */
final class CatalogExportPersister implements DataPersisterInterface
{
    public function supports(string $type): bool
    {
        return $type === 'catalog-exports';
    }

    public function instantiate(string $type): object
    {
        return new CatalogExport();
    }

    public function create(string $type, object $entity): object
    {
        return AcceptedForProcessing::poll('/export-jobs/job-processing')
            ->withJob(new ExportJob(id: 'job-processing', state: 'processing', exportId: ''), 'export-jobs')
            ->withRetryAfter(30);
    }

    public function update(string $type, object $entity): object
    {
        return $entity;
    }

    public function delete(string $type, object $entity): void {}

    public function mutateRelationship(
        string $type,
        object $entity,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
        bool $flush = true,
    ): object {
        return $entity;
    }
}
