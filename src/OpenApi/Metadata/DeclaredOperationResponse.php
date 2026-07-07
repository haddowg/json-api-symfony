<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;

/**
 * A per-operation success-response declaration rehydrated from the compiled
 * container — the plain (status, jobType) scalar pair the {@see TypeMetadata}
 * carries — and handed back to the core projector via {@see TypeMetadata::responsesFor()}.
 *
 * The typed, self-validating construction happens once at the attribute boundary
 * ({@see \haddowg\JsonApiBundle\Attribute\AsJsonApiResource}, via core's atomic
 * {@see \haddowg\JsonApi\OpenApi\Metadata\Created} et al.); by the time a declaration
 * round-trips through the container's scalar tag it has already been validated, so
 * this is a thin carrier the projector reads for `status()`/`jobType()` — it does not
 * implement the per-op marker interfaces and must not be re-validated.
 */
final readonly class DeclaredOperationResponse implements OperationResponseInterface
{
    public function __construct(
        private int $status,
        private ?string $jobType = null,
    ) {}

    public function status(): int
    {
        return $this->status;
    }

    public function jobType(): ?string
    {
        return $this->jobType;
    }
}
