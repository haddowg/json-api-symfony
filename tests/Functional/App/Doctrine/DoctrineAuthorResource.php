<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseAuthorResource;

/**
 * The Doctrine kernel's `authors` resource: the shared declaration mapped to its
 * backing entity via `#[AsJsonApiResource(entity: …)]`, read by the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * to route the type to the Doctrine provider — exactly as
 * {@see DoctrineArticleResource} is mapped.
 */
#[AsJsonApiResource(entity: AuthorEntity::class)]
final class DoctrineAuthorResource extends BaseAuthorResource {}
