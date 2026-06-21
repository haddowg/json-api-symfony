<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;

/**
 * One server's worth of OpenAPI metadata, assembled by the {@see MetadataSource}
 * into a plain immutable carrier the core {@see \haddowg\JsonApi\OpenApi\OpenApiProjector}
 * consumes to build one document.
 *
 * The info / server / tag-definition / security members are config-shaped (carried
 * ready-made from {@see ServerDocumentConfig} + the resolved per-server base URI);
 * the type list carries the JSON:API semantics the projector interprets.
 */
final readonly class ServerMetadata implements ServerMetadataInterface
{
    /**
     * @param list<Server>                   $servers
     * @param list<Tag>                      $tags
     * @param array<string, SecurityScheme> $securitySchemes
     * @param list<SecurityRequirement>     $defaultSecurity
     * @param list<TypeMetadataInterface>   $types
     */
    public function __construct(
        private string $title,
        private string $version,
        private ?string $description,
        private ?Contact $contact,
        private ?License $license,
        private array $servers,
        private string $jsonApiVersion,
        private array $tags,
        private array $securitySchemes,
        private array $defaultSecurity,
        private ?ExternalDocumentation $externalDocs,
        private array $types,
        private ?AtomicOperationsMetadataInterface $atomicOperations = null,
    ) {}

    public function title(): string
    {
        return $this->title;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function contact(): ?Contact
    {
        return $this->contact;
    }

    public function license(): ?License
    {
        return $this->license;
    }

    public function servers(): array
    {
        return $this->servers;
    }

    public function jsonApiVersion(): string
    {
        return $this->jsonApiVersion;
    }

    public function tags(): array
    {
        return $this->tags;
    }

    public function securitySchemes(): array
    {
        return $this->securitySchemes;
    }

    public function defaultSecurity(): array
    {
        return $this->defaultSecurity;
    }

    public function externalDocs(): ?ExternalDocumentation
    {
        return $this->externalDocs;
    }

    public function types(): array
    {
        return $this->types;
    }

    public function atomicOperations(): ?AtomicOperationsMetadataInterface
    {
        return $this->atomicOperations;
    }
}
