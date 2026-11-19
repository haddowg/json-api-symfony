<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataPersister\AcceptedForProcessing;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;

/**
 * Accepts every export for later processing.
 */
final class TrExportsPersister implements DataPersisterInterface
{
    public function supports(string $type): bool
    {
        return $type === 'tr-exports';
    }

    public function instantiate(string $type): object
    {
        return new TrExport();
    }

    public function create(string $type, object $entity): object
    {
        return AcceptedForProcessing::poll('https://example.test/tr-jobs/job-1')->withJob(new TrJob('job-1', 'queued'), 'tr-jobs');
    }

    public function update(string $type, object $entity): object
    {
        return $entity;
    }

    public function delete(string $type, object $entity): void {}

    public function mutateRelationship(string $type, object $entity, RelationInterface $relation, ToOneRelationship|ToManyRelationship $linkage, Mode $mode, bool $flush = true): object
    {
        return $entity;
    }
}
