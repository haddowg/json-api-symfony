<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes;

/**
 * The plain model behind the in-memory `settings` type.
 */
final class Setting
{
    /**
     * @param array<string, mixed>|null $window
     * @param list<mixed>|null          $marks
     */
    public function __construct(
        public string $id = '',
        public mixed $value = null,
        public mixed $answer = null,
        public mixed $threshold = null,
        public mixed $startsOn = null,
        public ?array $window = null,
        public ?array $marks = null,
        public ?string $currency = null,
    ) {}
}
