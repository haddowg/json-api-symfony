<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

/**
 * The hand-written documents the `notes` overrides replace generated examples with.
 */
final class NoteExamples
{
    public const array FETCH_ONE = ['data' => ['type' => 'notes', 'id' => '42', 'attributes' => ['title' => 'Written by hand']]];

    public const array CREATE_REQUEST = ['data' => ['type' => 'notes', 'attributes' => ['title' => 'Draft']]];

    public const array PIN_RESPONSE = ['meta' => ['pinned' => true]];
}
