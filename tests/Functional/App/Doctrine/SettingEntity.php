<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped setting, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ValueTypes\Setting}: each union, the
 * object holding two and the list of unions is one `json` column. Not `final` so
 * Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'setting')]
class SettingEntity
{
    /**
     * @param array<string, mixed>|null $window
     * @param list<mixed>|null          $marks
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public mixed $value = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public mixed $answer = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public mixed $threshold = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public mixed $startsOn = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $window = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $marks = null,
        #[ORM\Column(nullable: true)]
        public ?string $currency = null,
    ) {}
}
