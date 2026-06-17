<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping;

/**
 * A trivial model for the exception-mapping harness `throwingWidgets` collection
 * read. The single seeded instance exists only so the `GET /throwingWidgets`
 * collection read reaches the resource's {@see ThrowingWidgetResource::afterFetchCollection()}
 * hook, where the chosen test exception is thrown.
 */
final class ThrowingWidget
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
