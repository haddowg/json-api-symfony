<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory `FlattenCountry` resource of the flattened-attribute (`on()`)
 * conformance fixture (bundle ADR 0085): the SECOND level the book's nested
 * `author.country` pin walks to, served by an
 * {@see \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider}. Autoconfigured to
 * the resource tag by the in-memory kernel.
 */
final class InMemoryFlattenCountryResource extends BaseFlattenCountryResource {}
