<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped preset, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\OptionNamedFields\Preset}. Not
 * `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'preset')]
class PresetEntity
{
    /**
     * @param array<string, mixed>|null $options
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(name: 'group_names')]
        public string $groups = '',
        #[ORM\Column(name: 'field_names', nullable: true)]
        public ?string $fields = null,
        #[ORM\Column(name: 'payload_value', nullable: true)]
        public ?string $payload = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $options = null,
    ) {}
}
