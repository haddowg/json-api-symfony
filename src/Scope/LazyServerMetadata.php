<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Scope;

use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;

/**
 * A server's metadata, built the first time something reads it.
 *
 * A server's scope enforcer is created with the server, but its table builder reads the
 * server's metadata, which is built from that same server. Deferring the build until the
 * enforcer needs to resolve an operation breaks the cycle.
 *
 * @internal
 */
final class LazyServerMetadata implements ServerMetadataInterface
{
    private ?ServerMetadataInterface $metadata = null;

    /**
     * @param \Closure(): ServerMetadataInterface $build
     */
    public function __construct(private readonly \Closure $build) {}

    public function title(): string
    {
        return $this->metadata()->title();
    }

    public function version(): string
    {
        return $this->metadata()->version();
    }

    public function description(): ?string
    {
        return $this->metadata()->description();
    }

    public function contact(): ?Contact
    {
        return $this->metadata()->contact();
    }

    public function license(): ?License
    {
        return $this->metadata()->license();
    }

    public function servers(): array
    {
        return $this->metadata()->servers();
    }

    public function jsonApiVersion(): string
    {
        return $this->metadata()->jsonApiVersion();
    }

    public function tags(): array
    {
        return $this->metadata()->tags();
    }

    public function securitySchemes(): array
    {
        return $this->metadata()->securitySchemes();
    }

    public function defaultSecurity(): array
    {
        return $this->metadata()->defaultSecurity();
    }

    public function externalDocs(): ?ExternalDocumentation
    {
        return $this->metadata()->externalDocs();
    }

    public function profiles(): array
    {
        return $this->metadata()->profiles();
    }

    public function types(): array
    {
        return $this->metadata()->types();
    }

    public function atomicOperations(): ?AtomicOperationsMetadataInterface
    {
        return $this->metadata()->atomicOperations();
    }

    private function metadata(): ServerMetadataInterface
    {
        return $this->metadata ??= ($this->build)();
    }
}
