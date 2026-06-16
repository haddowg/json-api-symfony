<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The require-client-id witness entity: a natural-key string id with no DB generator.
 * Its {@see TokenResource} declares `requireClientId()`, so a create must carry the
 * client `id` (a create without one 403s `ClientGeneratedIdRequired`). Not `final` so
 * Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'id_source_token')]
class TokenEntity
{
    #[ORM\Id]
    #[ORM\Column]
    public string $id = '';

    #[ORM\Column]
    public string $value = '';
}
