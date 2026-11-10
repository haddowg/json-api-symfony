<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\SubmissionKind;

/**
 * The Doctrine-mapped submission, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes\Submission}: each object
 * attribute and list is one `json` column. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'submission')]
class SubmissionEntity
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
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column(nullable: true)]
        public ?int $count = null,
        #[ORM\Column(nullable: true)]
        public ?float $ratio = null,
        #[ORM\Column(nullable: true)]
        public ?bool $flag = null,
        #[ORM\Column(type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $day = null,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $at = null,
        #[ORM\Column(type: 'string', nullable: true, enumType: SubmissionKind::class)]
        public ?SubmissionKind $kind = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $tags = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $options = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $rows = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $mapping = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $block = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $review = null,
    ) {}
}
