<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use Doctrine\ORM\Mapping as ORM;

/**
 * The one entity behind every type of the deprecation slice: `bulletins` and the
 * plain `memos`, `pamphlets`, `leaflets` and `notices`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'deprecation_bulletin')]
class Bulletin
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $title = '';

    #[ORM\Column(type: 'string')]
    public string $code = '';

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?Bulletin $parent = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?Bulletin $editor = null;

    public function __construct(?int $id = null, string $title = '', string $code = '')
    {
        $this->id = $id;
        $this->title = $title;
        $this->code = $code;
    }
}
