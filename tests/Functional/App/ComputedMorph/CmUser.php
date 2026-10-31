<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedMorph;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user whose companies span three types, held as type and id rows on a join entity
 * rather than as an association the provider could query.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cm_user')]
class CmUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    /**
     * @var Collection<int, CmUserCompany>
     */
    #[ORM\OneToMany(targetEntity: CmUserCompany::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $userCompanies;

    public function __construct()
    {
        $this->userCompanies = new ArrayCollection();
    }

    public function addCompany(string $type, int $id): void
    {
        $this->userCompanies->add(new CmUserCompany($this, $type, $id));
    }

    /**
     * @return list<string> each company as `type:id`, in stored order
     */
    public function companyKeys(): array
    {
        return \array_values(\array_map(static fn(CmUserCompany $row): string => $row->key(), $this->userCompanies->toArray()));
    }
}
