<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresAcceptedBodies;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionExample;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionExtensions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionLink;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionSafety;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionScopes;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApiBundle\Action\AcceptedMediaTypes;
use haddowg\JsonApiBundle\Action\ActionDescriptor;
use haddowg\JsonApiBundle\Action\RawResults;

/**
 * Adapts a bundle {@see ActionDescriptor} (a resolved `#[AsJsonApiAction]`) to the
 * OpenAPI {@see ActionMetadataInterface} the projector consumes.
 *
 * The bundle and core each carry their own {@see \haddowg\JsonApiBundle\Action\ActionScope}
 * / {@see \haddowg\JsonApiBundle\Action\ActionInput} and core
 * {@see ActionScope} / {@see ActionInputMode} enums (same case names, different
 * namespaces — the bundle's drive routing/dispatch, core's drive projection), so
 * they are mapped **by case name**.
 *
 * `isSecured()` reads the descriptor's resolved `security` expression presence (the
 * expression itself is never surfaced — design §4.6/D8). `responds()` rehydrates the
 * descriptor's scalar success-response set (a `kind` discriminator + optional
 * `type`/`jobType`) into core's atomic {@see ActionResponse} objects — the values the
 * projector switches on to advertise a `200` resource document, a `200` meta document,
 * a `204`, a `202` async accept, or a `303` redirect.
 */
final readonly class ActionMetadata implements DeclaresActionLink, DeclaresAcceptedBodies, DeclaresActionExample, DeclaresActionSafety, DeclaresActionExtensions, DeclaresActionScopes
{
    public function __construct(private ActionDescriptor $descriptor) {}

    public function path(): string
    {
        return $this->descriptor->path;
    }

    public function methods(): array
    {
        return $this->descriptor->methods;
    }

    public function scope(): ActionScope
    {
        return ActionScope::{$this->descriptor->scope->name};
    }

    public function inputMode(): ActionInputMode
    {
        return ActionInputMode::{$this->descriptor->input->name};
    }

    public function inputType(): ?string
    {
        // The contract carries the input type only in Document mode (None/Raw read no
        // JSON:API request schema); the descriptor still stores the mount-type default
        // for every mode, so it is surfaced only when the input is a Document.
        return $this->inputMode() === ActionInputMode::Document ? $this->descriptor->inputType : null;
    }

    public function responds(): array
    {
        $responses = [];
        foreach ($this->descriptor->responds as $entry) {
            $responses[] = match ($entry['kind']) {
                'resource' => new ActionResource($entry['type'] ?? $this->descriptor->type),
                'meta' => new MetaResult(),
                'nocontent' => new NoContent(),
                'accepted' => new Accepted($entry['jobType'] ?? ''),
                'seeother' => new SeeOther($entry['description'] ?? null, $entry['mediaType'] ?? null),
                'raw' => RawResults::fromArray($entry['raw'] ?? []),
                default => throw new \LogicException(\sprintf(
                    'Unknown action response kind "%s" for action "%s" on type "%s".',
                    $entry['kind'],
                    $this->descriptor->path,
                    $this->descriptor->type,
                )),
            };
        }

        // The compiler pass always resolves a non-empty set (defaulting to a mount-type
        // resource document); guard defensively so the contract's non-empty promise holds.
        if ($responses === []) {
            return [new ActionResource($this->descriptor->outputType !== '' ? $this->descriptor->outputType : $this->descriptor->type)];
        }

        return $responses;
    }

    public function isSecured(): bool
    {
        return $this->descriptor->security !== null;
    }

    public function tags(): array
    {
        return $this->descriptor->tags;
    }

    public function summary(): ?string
    {
        return $this->descriptor->summary;
    }

    public function description(): ?string
    {
        return $this->descriptor->description;
    }

    /**
     * The action's path, the `links` member an `asLink` action renders as; null for any
     * other action.
     */
    public function accepts(): array
    {
        return AcceptedMediaTypes::bodies($this->descriptor->accepts);
    }

    public function linkName(): ?string
    {
        return $this->descriptor->asLink ? $this->descriptor->path : null;
    }

    public function example(): OperationExample|false|null
    {
        $example = $this->descriptor->example;

        return $example === null ? null : OperationExampleCodec::toExample($example);
    }

    public function readOnly(): ?bool
    {
        return $this->descriptor->readOnly;
    }

    public function destructive(): ?bool
    {
        return $this->descriptor->destructive;
    }

    public function idempotent(): ?bool
    {
        return $this->descriptor->idempotent;
    }

    public function extensions(): array
    {
        return $this->descriptor->extensions;
    }

    public function scopes(): ?array
    {
        return $this->descriptor->scopes;
    }
}
