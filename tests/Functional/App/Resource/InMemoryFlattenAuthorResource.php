<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory `FlattenAuthor` resource of the flattened-attribute (`on()`) conformance
 * fixture (bundle ADR 0085): the shared declaration served by an
 * {@see \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider}. Autoconfigured to
 * the resource tag by the in-memory kernel.
 */
final class InMemoryFlattenAuthorResource extends BaseFlattenAuthorResource {}
