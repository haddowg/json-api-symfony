<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\FixedMembershipArticleResource;

/**
 * The Doctrine kernel's fixed membership `articles` resource: the shared
 * {@see FixedMembershipArticleResource} declaration mapped to its entity.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineFixedMembershipArticleResource extends FixedMembershipArticleResource {}
