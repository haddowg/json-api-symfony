<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action;

/**
 * The shape the storage-agnostic custom-action handlers mutate. Both the in-memory
 * {@see Widget} POJO and the Doctrine
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Action\Doctrine\WidgetEntity}
 * implement it, so an action handler reads/writes the resolved entity through one
 * type regardless of provider — keeping the handlers provider-agnostic (the whole
 * point of the dual-provider conformance run).
 */
interface MutableWidget
{
    public function widgetName(): string;

    public function applyName(string $name): void;

    public function publish(): void;

    public function attachArtwork(string $artwork): void;
}
