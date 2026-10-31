<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationshipEntityConstraints;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rec_team')]
class Team
{
    /**
     * @var iterable<Company>
     */
    #[ORM\ManyToMany(targetEntity: Company::class)]
    #[ORM\JoinTable(name: 'rec_team_company')]
    public iterable $companies;

    /**
     * @param list<Company> $companies
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        array $companies = [],
    ) {
        $this->companies = new ArrayCollection($companies);
    }
}
