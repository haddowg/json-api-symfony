<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use Doctrine\ORM\Mapping as ORM;

/**
 * A file whose `target` is stored as a type and an id rather than an association, and
 * whose `watchers` are a list of member ids.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fill_file')]
class FillFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $targetType = null;

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $targetId = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    public array $watcherIds = [];
}
