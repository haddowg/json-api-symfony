<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
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
 * expression itself is never surfaced — design §4.6/D8). `outputType()` maps an
 * empty `outputType` (the "no response body" sentinel) to `null` (the contract's
 * `204 No Content` case) and otherwise returns the named type. An action declares the
 * sentinel by setting `#[AsJsonApiAction(returns204: true)]`, which suppresses the
 * mount-type default in the {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
 * so a body-less (`204`) action advertises a `204` response instead of a `200`
 * document body (design §4.5); an action that omits `returns204` defaults its
 * `outputType` to the mount type as before.
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

    public function outputType(): ?string
    {
        return $this->descriptor->outputType !== '' ? $this->descriptor->outputType : null;
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
