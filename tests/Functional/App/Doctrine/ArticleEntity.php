<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped article: the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Article}, persisted to the
 * test SQLite database. The id is application-assigned (no generator), matching
 * the string ids the fixtures use. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'article')]
class ArticleEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public string $id = '',
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column]
        public string $body = '',
        #[ORM\Column]
        public string $category = '',
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $publishedAt = null,
    ) {}
}
