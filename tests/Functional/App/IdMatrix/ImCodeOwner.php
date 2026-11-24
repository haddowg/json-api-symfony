<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\ORM\Mapping as ORM;

/**
 * An owner keyed by a `im_code` id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_code_owner')]
class ImCodeOwner
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: CodeType::NAME)]
        public Code $id,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {}
}
