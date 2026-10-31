<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\Exception\ErrorCatalogSourceInterface;
use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ContributesErrorCodes;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresExampleGeneration;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresLinkObjects;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresMethodNotAllowed;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationScopeTable;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresReadOnlyAttributeRejection;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApi\Scope\ScopeTable;

/**
 * One server's worth of OpenAPI metadata, assembled by the {@see MetadataSource}
 * into a plain immutable carrier the core {@see \haddowg\JsonApi\OpenApi\OpenApiProjector}
 * consumes to build one document.
 *
 * The info / server / tag-definition / security members are config-shaped (carried
 * ready-made from {@see ServerDocumentConfig} + the resolved per-server base URI);
 * the type list carries the JSON:API semantics the projector interprets.
 *
 * It implements core's {@see ContributesErrorCodes} (a {@see ServerMetadataInterface}
 * that also names error sources) rather than the bare interface, so an application's
 * own described errors join core's in the projected catalogue. The sources come from
 * the {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ErrorCatalogPass}; a
 * server with none contributes an empty list, which changes no document.
 *
 * It also implements core's {@see DeclaresLinkObjects}, so the links components describe
 * the form the server renders its links in and each example `describedby` points into
 * the same document URL the server's links do.
 *
 * And core's {@see DeclaresExampleGeneration}, carrying the server's
 * `openapi_examples` setting (`json_api.openapi.examples` unless a named server sets
 * its own).
 *
 * And core's {@see DeclaresOperationScopeTable}, handing the projection the same scope
 * table enforcement checks, read from the {@see \haddowg\JsonApiBundle\Scope\ScopeTableProvider}
 * only when the projection asks for it.
 */
final readonly class ServerMetadata implements ContributesErrorCodes, DeclaresLinkObjects, DeclaresReadOnlyAttributeRejection, DeclaresExampleGeneration, DeclaresOperationScopeTable, DeclaresMethodNotAllowed
{
    /**
     * @param list<Server>                      $servers
     * @param list<Tag>                         $tags
     * @param array<string, SecurityScheme>     $securitySchemes
     * @param list<SecurityRequirement>         $defaultSecurity
     * @param list<TypeMetadataInterface>       $types
     * @param list<string>                      $profiles
     * @param list<ErrorCatalogSourceInterface> $errorSources
     * @param (\Closure(): ScopeTable)|null      $scopeTable   reads the server's scope table when the projection asks for it; null uses no scopes
     * @param array<string, string>             $scopeCatalog every scope the app defines, with its description
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
        private array $profiles = [],
        private array $errorSources = [],
        private bool $rendersLinkObjects = true,
        private ?string $documentUrl = null,
        private bool $rejectsReadOnlyAttributes = false,
        private bool $generatesExamples = true,
        private ?\Closure $scopeTable = null,
        private string $scopeScheme = 'oauth2',
        private array $scopeCatalog = [],
    ) {}

    public function scopeTable(): ScopeTable
    {
        return $this->scopeTable !== null ? ($this->scopeTable)() : new ScopeTable();
    }

    public function scopeScheme(): string
    {
        return $this->scopeScheme;
    }

    public function scopeCatalog(): array
    {
        return $this->scopeCatalog;
    }

    public function generatesExamples(): bool
    {
        return $this->generatesExamples;
    }

    public function rejectsReadOnlyAttributes(): bool
    {
        return $this->rejectsReadOnlyAttributes;
    }

    /**
     * The exception listener renders a method the path does not accept as a JSON:API
     * `405` on every server's paths.
     */
    public function rendersMethodNotAllowed(): bool
    {
        return true;
    }

    public function rendersLinkObjects(): bool
    {
        return $this->rendersLinkObjects;
    }

    public function documentUrl(): ?string
    {
        return $this->documentUrl;
    }

    /**
     * @return list<ErrorCatalogSourceInterface>
     */
    public function errorSources(): iterable
    {
        return $this->errorSources;
    }

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

    public function profiles(): array
    {
        return $this->profiles;
    }
}
