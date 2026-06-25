<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Doctrine\ORM\Mapping as ORM;

/**
 * The `securedWidgets` entity: gated by role expressions on its resource (a default
 * `is_granted('ROLE_USER')`, with per-operation `ROLE_ADMIN` overrides for create
 * and delete). A self-referential `partner` ({@see ORM\ManyToOne}) backs the
 * per-relation-security witnesses (core ADR 0099 / bundle ADR 0100): several relations
 * share this one column, differing only in their declared `security()`. Not `final` so
 * Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'secured_widget')]
class SecuredWidgetEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        #[ORM\GeneratedValue]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'partner_id', referencedColumnName: 'id', nullable: true)]
        public ?SecuredWidgetEntity $partner = null,
    ) {}
}
