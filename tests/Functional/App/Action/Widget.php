<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

/**
 * The plain mount-type model the custom-action conformance suite hangs its actions
 * off (bundle ADR 0076). A `widgets` resource serves it over the in-memory provider;
 * the Doctrine kernel maps the parallel
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Action\Doctrine\WidgetEntity}.
 *
 * `published`/`uploadedArtwork` are the side-effects the resource-scope Document
 * action ("publish") and the Raw upload action ("artwork") apply, asserted by a
 * follow-up read.
 */
final class Widget implements MutableWidget
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public bool $published = false,
        public ?string $uploadedArtwork = null,
        // A self-referential to-one the in-memory WidgetResource exposes as a `related`
        // relation, so `?include=related` renders another widget as an INCLUDED member
        // — the witness that the asLink contributor runs for every rendered resource of
        // the type, not only the primary one (bundle ADR 0091).
        public ?Widget $related = null,
    ) {}

    public function widgetName(): string
    {
        return $this->name;
    }

    public function applyName(string $name): void
    {
        $this->name = $name;
    }

    public function publish(): void
    {
        $this->published = true;
    }

    public function attachArtwork(string $artwork): void
    {
        $this->uploadedArtwork = $artwork;
    }
}
