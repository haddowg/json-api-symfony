<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping;

/**
 * A plain domain exception the tagged {@see TestExceptionMapper} maps to a rich
 * JSON:API error (custom status + source + meta) — the
 * {@see \haddowg\JsonApiBundle\EventListener\ExceptionMapperInterface} facet
 * (bundle ADR 0073). The throwing-resource hook raises it for `?throwSignal=mapper`.
 */
final class MapperMappedException extends \RuntimeException {}
