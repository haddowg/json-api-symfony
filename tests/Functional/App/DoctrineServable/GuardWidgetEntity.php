<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable;

use Doctrine\ORM\Mapping as ORM;

/**
 * The entity behind the Doctrine servability warm-up guard fixture (guards A3 + A7):
 * a plain widget with a real `title` column and NO association reaching the far
 * {@see GuardGadgetEntity} — so a pivot `belongsToMany` declared on its resource
 * cannot be auto-detected (guard A7), and a computed() field marked sortable() /
 * a filter on a non-column has no backing here (guard A3). Not `final` so Doctrine
 * may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'guard_widget')]
class GuardWidgetEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
    ) {}
}
