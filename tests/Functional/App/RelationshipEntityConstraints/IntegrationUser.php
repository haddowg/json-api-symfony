<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationshipEntityConstraints;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The in-memory store keeps `companies` as a list, Doctrine as a collection.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rec_integration_user')]
class IntegrationUser
{
    #[ORM\ManyToOne(targetEntity: Company::class)]
    public ?Company $primaryCompany = null;

    /**
     * @var iterable<Company>
     */
    #[ORM\ManyToMany(targetEntity: Company::class)]
    #[ORM\JoinTable(name: 'rec_integration_user_company')]
    public iterable $companies;

    /**
     * @param list<Company> $companies
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
        ?Company $primaryCompany = null,
        array $companies = [],
    ) {
        $this->primaryCompany = $primaryCompany;
        $this->companies = new ArrayCollection($companies);
    }
}
