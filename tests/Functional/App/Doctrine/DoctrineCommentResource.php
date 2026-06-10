<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseCommentResource;

/**
 * The Doctrine kernel's `comments` resource: the shared declaration mapped to
 * its backing entity via `#[AsJsonApiResource(entity: …)]`, read by the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * to route the type to the Doctrine provider — exactly as
 * {@see DoctrineArticleResource} is mapped.
 */
#[AsJsonApiResource(entity: CommentEntity::class)]
final class DoctrineCommentResource extends BaseCommentResource {}
