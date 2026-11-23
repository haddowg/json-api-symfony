<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OptionNamedFields;

/**
 * The plain model behind the in-memory `presets` type.
 */
final class Preset
{
    /**
     * @param array<string, mixed>|null $options
     */
    public function __construct(
        public string $id = '',
        public string $groups = '',
        public ?string $fields = null,
        public ?string $payload = null,
        public ?array $options = null,
    ) {}
}
