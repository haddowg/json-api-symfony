<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Async;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataPersister\AcceptedForProcessing;
use haddowg\JsonApiBundle\DataPersister\DataPersisterInterface;
use haddowg\JsonApiBundle\Tests\Functional\App\Article;

/**
 * An `articles` persister that accepts every write for **asynchronous processing**
 * instead of committing it: {@see create()} / {@see update()} dispatch the work
 * (here, nothing — a real one would enqueue a Symfony Messenger message) and return
 * an {@see AcceptedForProcessing} pointing at a pollable {@see Job}. The witness for
 * the bundle's async-write seam (bundle ADR 0110): the handler renders these as a
 * `202 Accepted` with `Content-Location` + `Retry-After` rather than a `201`/`200`.
 */
final class AsyncArticlesPersister implements DataPersisterInterface
{
    public function supports(string $type): bool
    {
        return $type === 'articles';
    }

    public function instantiate(string $type): object
    {
        return new Article();
    }

    public function create(string $type, object $entity): object
    {
        // A real persister would dispatch the create to a queue here; the witness just
        // accepts it, pointing at the job resource the client polls.
        return AcceptedForProcessing::poll('https://example.test/jobs/job-1')
            ->withJob(new Job('job-1', 'queued'), 'jobs')
            ->withRetryAfter(30);
    }

    public function update(string $type, object $entity): object
    {
        return AcceptedForProcessing::poll('https://example.test/jobs/job-2')
            ->withJob(new Job('job-2', 'queued'), 'jobs')
            ->withRetryAfter(30);
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
