<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\Field\AbstractFieldBuilder;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseArticleResource;
use haddowg\JsonApiBundle\Validation\Constraint\UniqueEntity;

/**
 * The Doctrine kernel's `articles` resource: the shared declaration mapped to
 * its backing entity via `#[AsJsonApiResource(entity: …)]`, which is what the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * reads to route the type to the
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider}.
 *
 * It additionally declares a {@see UniqueEntity} on `title` — a Doctrine-only,
 * entity-level rule the in-memory resource has no equivalent for — so the
 * post-hydration validation seam is exercised against a real repository.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineArticleResource extends BaseArticleResource
{
    public function fields(): array
    {
        $fields = parent::fields();
        foreach ($fields as $field) {
            if ($field instanceof AbstractFieldBuilder && $field->name() === 'title') {
                $field->constrain(new UniqueEntity(['title']));
            }
        }

        return $fields;
    }
}
