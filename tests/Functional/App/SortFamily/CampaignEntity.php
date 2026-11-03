<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sf_campaign')]
class CampaignEntity implements HasPlaceholderValues
{
    /**
     * @var Collection<int, CampaignValueEntity>
     */
    #[ORM\OneToMany(targetEntity: CampaignValueEntity::class, mappedBy: 'campaign')]
    public Collection $values;

    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {
        $this->values = new ArrayCollection();
    }

    public function placeholderValue(string $name): ?string
    {
        foreach ($this->values as $value) {
            if ($value->name === $name) {
                return $value->value;
            }
        }

        return null;
    }
}
