<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Doctrine\ORM\Mapping as ORM;

/**
 * The `openWidgets` entity: its resource declares **no** security, so every
 * operation is ungated by the authorization layer (the witness that a resource
 * without `security` is unaffected). Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'open_widget')]
class OpenWidgetEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        #[ORM\GeneratedValue]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {}
}
