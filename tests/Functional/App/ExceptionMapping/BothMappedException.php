<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping;

/**
 * A plain domain exception matched by **both** facets of the mapping seam (bundle
 * ADR 0073): the {@see ExceptionMappingTestKernel}'s `json_api.exceptions` config
 * map points it at a status (the low-priority {@see \haddowg\JsonApiBundle\EventListener\ConfiguredExceptionMapper}),
 * and the tagged {@see TestExceptionMapper} (default priority `0`, consulted first)
 * also maps it to a rich error. The ordering test asserts the tagged mapper wins
 * over the config map. The throwing-resource hook raises it for `?throwSignal=both`.
 */
final class BothMappedException extends \RuntimeException {}
