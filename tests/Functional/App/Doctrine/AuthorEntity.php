<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped author: the related entity an {@see ArticleEntity}'s
 * to-one `author` association points at, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Author}. The id is
 * application-assigned (no generator). Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'author')]
class AuthorEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column]
        public string $name = '',
    ) {}
}
