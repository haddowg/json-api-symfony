<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineServable;

use Doctrine\ORM\Mapping as ORM;

/**
 * The far type of the pivot-resolvability warm-up guard fixture (guard A7): the
 * `gadgets` a {@see GuardWidgetEntity} pivot `belongsToMany` would reach. Because
 * {@see GuardWidgetEntity} declares NO to-many association reaching this entity,
 * the association entity cannot be auto-detected — the pivot is unresolvable, which
 * guard A7 must surface at `cache:warmup`. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'guard_gadget')]
class GuardGadgetEntity
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
