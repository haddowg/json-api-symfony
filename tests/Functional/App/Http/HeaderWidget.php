<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Http;

/**
 * A minimal POJO backing the response-header witnesses (bundle ADR 0054): one
 * mutable `name` attribute so the cached/deprecated resources can be created, read
 * and written without any storage coupling.
 */
final class HeaderWidget
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
    ) {}
}
