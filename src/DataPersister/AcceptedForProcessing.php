<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

/**
 * The marker a {@see DataPersisterInterface::create()} / {@see DataPersisterInterface::update()}
 * returns — in place of the persisted entity — to signal that the write was
 * **accepted for asynchronous processing** rather than committed. The
 * {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} renders it as a
 * `202 Accepted` (core's {@see \haddowg\JsonApi\Response\AcceptedResponse}) instead of
 * the `201`/`200` a synchronous write returns.
 *
 * This is the bundle's thin async-write seam: a persister that dispatches the work
 * (a Symfony Messenger message, say) instead of persisting inline returns this,
 * pointing at a job resource the client can poll ({@see poll()} sets the
 * `Content-Location`). When the job completes, that job endpoint responds with a
 * `303 See Other` (see {@see \haddowg\JsonApiBundle\Action\ActionContext::seeOther()})
 * pointing at the produced resource — the JSON:API 1.1 asynchronous-processing
 * lifecycle. Nothing about *how* the work is queued is baked in; the persister owns
 * that decision (bundle ADR 0110).
 *
 * The `202` body is either the pollable job resource ({@see withJob()}, rendered
 * through the job type's registered serializer) or a meta-only status document
 * ({@see withMeta()}); with neither it is an empty status document.
 */
final class AcceptedForProcessing
{
    private int|\DateTimeInterface|null $retryAfter = null;

    private ?object $job = null;

    private ?string $jobType = null;

    /**
     * @var array<string, mixed>
     */
    private array $meta = [];

    private function __construct(
        private readonly string $contentLocation,
    ) {}

    /**
     * Accepts the write for async processing, advertising the URL of the job
     * resource the client polls for completion in the `Content-Location` header.
     */
    public static function poll(string $contentLocation): self
    {
        return new self($contentLocation);
    }

    /**
     * Renders the given job object as the `202` body's primary `data`, through the
     * `$jobType`'s registered serializer — the representation of the accepted
     * operation's progress the client polls.
     */
    public function withJob(object $job, string $jobType): self
    {
        $self = clone $this;
        $self->job = $job;
        $self->jobType = $jobType;

        return $self;
    }

    /**
     * Sets the `Retry-After` header hinting when the client should next poll the job
     * resource — an `int` (delta-seconds) or a {@see \DateTimeInterface} (an HTTP-date).
     */
    public function withRetryAfter(int|\DateTimeInterface $after): self
    {
        $self = clone $this;
        $self->retryAfter = $after;

        return $self;
    }

    /**
     * Seeds the top-level `meta` of a meta-only `202` status document (ignored when a
     * job resource is set via {@see withJob()}, which renders `data` instead).
     *
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        $self = clone $this;
        $self->meta = $meta;

        return $self;
    }

    public function contentLocation(): string
    {
        return $this->contentLocation;
    }

    public function retryAfter(): int|\DateTimeInterface|null
    {
        return $this->retryAfter;
    }

    public function job(): ?object
    {
        return $this->job;
    }

    public function jobType(): ?string
    {
        return $this->jobType;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }
}
