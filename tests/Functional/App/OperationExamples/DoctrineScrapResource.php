<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Note::class, server: 'gappy', readOnly: true)]
final class DoctrineScrapResource extends ScrapResource {}
