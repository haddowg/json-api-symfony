<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApiBundle\Action\ActionDescriptor;

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
 * a `204`, a `202` async accept, or a `303` redirect (core ADR 0127).
 */
final readonly class ActionMetadata implements ActionMetadataInterface
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
                'seeother' => new SeeOther(),
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
        return null;
    }

    public function description(): ?string
    {
        return null;
    }
}
