<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class RunProgressResource extends AbstractResource
{
    public static string $type = 'runProgress';

    public function fields(): array
    {
        return [
            Id::make(),
            Integer::make('percent'),
        ];
    }

    public function filters(): array
    {
        return [Where::make('percent')];
    }
}
