<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped author: the related entity an {@see ArticleEntity}'s
 * to-one `author` association points at, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Author}. The id is
 * store-provided — an `AUTO` integer the database assigns on insert. Not `final`
 * so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'author')]
class AuthorEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {}
}
