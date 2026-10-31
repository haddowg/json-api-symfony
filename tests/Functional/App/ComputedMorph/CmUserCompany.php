<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cm_user_company')]
class CmUserCompany
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: CmUser::class, inversedBy: 'userCompanies')]
        #[ORM\JoinColumn(nullable: false)]
        public CmUser $user,
        #[ORM\Column(type: 'string')]
        public string $companyType,
        #[ORM\Column(type: 'integer')]
        public int $companyId,
    ) {}

    public function key(): string
    {
        return $this->companyType . ':' . $this->companyId;
    }
}
