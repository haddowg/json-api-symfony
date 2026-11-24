<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Member::class)]
final class DoctrineMemberResource extends MemberResource {}
