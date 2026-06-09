<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain domain model fed to the in-memory provider — no base class, no ORM —
 * mirroring the core getting-started example. Every property defaults to the
 * empty string so the in-memory persister can construct a blank instance for the
 * hydrator to populate on create (the same shape as the Doctrine
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity}).
 */
final class Article
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public string $body = '',
        public string $category = '',
        public ?\DateTimeImmutable $publishedAt = null,
        public ?string $couponCode = null,
    ) {}
}
