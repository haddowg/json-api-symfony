<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExceptionMapping;

/**
 * A plain domain exception — not a core
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} and not a Symfony
 * `HttpExceptionInterface` — that the
 * {@see ExceptionMappingTestKernel}'s `json_api.exceptions` config map points at a
 * status (the config-driven {@see \haddowg\JsonApiBundle\EventListener\ConfiguredExceptionMapper}
 * facet, bundle ADR 0073). The throwing-resource hook raises it for `?throwSignal=config`.
 */
final class ConfigMappedException extends \RuntimeException {}
