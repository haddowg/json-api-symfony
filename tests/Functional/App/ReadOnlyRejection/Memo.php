<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ReadOnlyRejection;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'read_only_memo')]
class Memo
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $body = '';

    #[ORM\Column(type: 'string')]
    public string $author = 'system';

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $slug = null;
}
