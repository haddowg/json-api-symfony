<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

/**
 * A note pointing at an owner of every id kind through a plain field each, at a post or
 * a video through one polymorphic pair with an integer id, at a uuid owner through a
 * polymorphic pair whose id is a `uuid` column, and at a guid, uuid or prefixed owner
 * through a text pair that may keep a uuid in capitals or an id in its wire form.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_note')]
class ImNote
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int|string $id,
        #[ORM\Column(type: 'string')]
        public string $title = '',
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $intOwnerId = null,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $strOwnerId = null,
        #[ORM\Column(type: 'uuid', nullable: true)]
        public ?Uuid $uuidOwnerId = null,
        #[ORM\Column(type: 'ulid', nullable: true)]
        public ?Ulid $ulidOwnerId = null,
        #[ORM\Column(type: 'guid', nullable: true)]
        public ?string $guidOwnerId = null,
        #[ORM\Column(type: CodeType::NAME, nullable: true)]
        public ?Code $codeOwnerId = null,
        #[ORM\Column(type: 'uuid', nullable: true)]
        public ?Uuid $encOwnerId = null,
        #[ORM\Column(type: 'uuid', nullable: true)]
        public ?Uuid $prefixOwnerId = null,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $subjectType = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $subjectId = null,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $targetType = null,
        #[ORM\Column(type: 'uuid', nullable: true)]
        public ?Uuid $targetId = null,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $refType = null,
        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        public ?string $refId = null,
    ) {}
}
