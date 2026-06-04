<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseArticleResource;

/**
 * The Doctrine kernel's `articles` resource: the shared declaration mapped to
 * its backing entity via `#[AsJsonApiResource(entity: …)]`, which is what the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * reads to route the type to the
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider}.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineArticleResource extends BaseArticleResource {}
