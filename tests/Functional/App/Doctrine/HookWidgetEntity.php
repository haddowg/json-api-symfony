<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `hookableWidgets` entity, the Doctrine twin of the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookWidget}: a writable
 * `name`, a `stamp` a before-create hook sets (witnessing a before-hook mutation
 * persisted through a real flush), and a to-one `owner` relationship. Not `final`
 * so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'hook_widget')]
class HookWidgetEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        #[ORM\GeneratedValue]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column]
        public string $stamp = '',
        #[ORM\ManyToOne(targetEntity: HookOwnerEntity::class)]
        #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: true)]
        public ?HookOwnerEntity $owner = null,
    ) {}
}
