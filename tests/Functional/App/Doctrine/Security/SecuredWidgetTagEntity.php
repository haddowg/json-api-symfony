<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use Doctrine\ORM\Mapping as ORM;

/**
 * The association entity behind `securedWidgets.adminTags`, a pivot relation whose
 * read is limited to admins.
 */
#[ORM\Entity]
#[ORM\Table(name: 'secured_widget_tag')]
class SecuredWidgetTagEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: SecuredWidgetEntity::class, inversedBy: 'widgetTags')]
        public ?SecuredWidgetEntity $widget = null,
        #[ORM\ManyToOne(targetEntity: OpenWidgetEntity::class)]
        public ?OpenWidgetEntity $tag = null,
        #[ORM\Column(type: 'integer')]
        public int $position = 0,
    ) {}
}
