<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Posts reading their notes by the bare id field of the notes' `subject`, which holds
 * video ids too, so warm-up refuses it.
 */
#[AsJsonApiResource(entity: ImPost::class)]
final class BareMorphIdPostResource extends AbstractResource
{
    public static string $type = 'im-posts';

    public function fields(): array
    {
        return [Id::make()->numeric(), HasMany::make('notes', 'im-notes', 'subjectId')];
    }
}
