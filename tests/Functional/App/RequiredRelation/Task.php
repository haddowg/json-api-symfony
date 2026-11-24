<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The in-memory store keeps `watchers` as a list, Doctrine as a collection. Neither
 * store refuses a task without an owner, so only validation can.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rr_task')]
class Task
{
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?Member $owner = null;

    /**
     * @var iterable<Member>
     */
    #[ORM\ManyToMany(targetEntity: Member::class)]
    #[ORM\JoinTable(name: 'rr_task_watcher')]
    public iterable $watchers;

    /**
     * @param list<Member> $watchers
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        #[ORM\Column(type: 'string')]
        public ?string $id = null,
        #[ORM\Column(type: 'string')]
        public string $title = '',
        ?Member $owner = null,
        array $watchers = [],
    ) {
        $this->owner = $owner;
        $this->watchers = new ArrayCollection($watchers);
    }
}
