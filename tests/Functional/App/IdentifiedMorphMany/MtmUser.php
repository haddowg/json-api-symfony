<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user whose companies span three types, stored as type and id join rows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mtm_user')]
class MtmUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    /**
     * @var Collection<int, MtmUserCompany>
     */
    #[ORM\OneToMany(targetEntity: MtmUserCompany::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $userCompanies;

    public function __construct()
    {
        $this->userCompanies = new ArrayCollection();
    }

    public function addCompany(string $type, int $id): void
    {
        $this->userCompanies->add(new MtmUserCompany($this, $type, $id));
    }

    /**
     * @return list<array{0: string, 1: int}> each company as its stored type and id, in stored order
     */
    public function companyPairs(): array
    {
        return \array_values(\array_map(static fn(MtmUserCompany $row): array => [$row->companyType, $row->companyId], $this->userCompanies->toArray()));
    }

    /**
     * @return list<string> each company as `type:id`, in stored order
     */
    public function companyKeys(): array
    {
        return \array_values(\array_map(static fn(MtmUserCompany $row): string => $row->key(), $this->userCompanies->toArray()));
    }
}
