<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource;

use Doctrine\ORM\Mapping as ORM;

/**
 * An {@see Advertiser} subclass with its own JSON:API type.
 */
#[ORM\Entity]
class Agency extends Advertiser
{
    #[ORM\Column(nullable: true)]
    public ?string $region = null;

    public function __construct(?int $id = null, string $name = '', ?string $region = null)
    {
        parent::__construct($id, $name);
        $this->region = $region;
    }
}
