<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes;

use haddowg\JsonApiBundle\Tests\Functional\App\Resource\SubmissionKind;

/**
 * The plain model behind the in-memory `submissions` type.
 */
final class Submission
{
    /**
     * @param list<mixed>|null          $tags
     * @param array<string, mixed>|null $options
     * @param list<mixed>|null          $rows
     * @param array<string, mixed>|null $mapping
     * @param array<string, mixed>|null $block
     * @param array<string, mixed>|null $review
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?int $count = null,
        public ?float $ratio = null,
        public ?bool $flag = null,
        public ?\DateTimeImmutable $day = null,
        public ?\DateTimeImmutable $at = null,
        public ?SubmissionKind $kind = null,
        public ?array $tags = null,
        public ?array $options = null,
        public ?array $rows = null,
        public ?array $mapping = null,
        public ?array $block = null,
        public ?array $review = null,
    ) {}
}
