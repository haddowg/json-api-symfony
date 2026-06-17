<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping;

/**
 * A plain domain exception matched by **neither** facet of the mapping seam — it is
 * not a core {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}, not a
 * Symfony `HttpExceptionInterface`, not named in the kernel's `json_api.exceptions`
 * config map, and not claimed by the tagged {@see TestExceptionMapper}. So it falls
 * through to the listener's generic-500 arm (bundle ADR 0073). The throwing-resource
 * hook raises it for `?throwSignal=unmapped`.
 */
final class UnmappedException extends \RuntimeException {}
