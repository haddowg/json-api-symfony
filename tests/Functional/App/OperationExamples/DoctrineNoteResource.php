<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(
    entity: Note::class,
    server: ['default', 'plain'],
    operationExamples: [
        'fetchOne' => new OperationExample(responses: [200 => NoteExamples::FETCH_ONE]),
        'create' => new OperationExample(request: NoteExamples::CREATE_REQUEST),
        'update' => false,
        'fetchRelationship.parent' => false,
    ],
)]
final class DoctrineNoteResource extends NoteResource {}
