<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionOutputMode;
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
 * expression itself is never surfaced — design §4.6/D8). `outputMode()` maps the
 * descriptor's resolved {@see \haddowg\JsonApiBundle\Action\ActionOutput} to core's
 * {@see ActionOutputMode} by case name — the discriminator the projector switches on
 * (a resource document / a meta-only document / a `204`). `outputType()` maps an
 * empty `outputType` (the "no response resource" sentinel a `returns204`/`outputMeta`
 * action carries) to `null` and otherwise returns the named type; it is read only in
 * {@see ActionOutputMode::Document}. An action declares the sentinel by setting
 * `#[AsJsonApiAction(returns204: true)]` (a `204`) or `outputMeta: true` (a meta
 * document), which suppresses the mount-type default in the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
 * (design §4.5, core ADR 0102); an action that omits both defaults its `outputType`
 * to the mount type as before.
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

    public function outputMode(): ActionOutputMode
    {
        return ActionOutputMode::{$this->descriptor->output->name};
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
