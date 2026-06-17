<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * The bundle's config-driven {@see ExceptionMapperInterface}: it maps a throwable
 * to a status-keyed JSON:API error when it is an instance of a class listed in the
 * `json_api.exceptions` map (class-string => HTTP status). It carries the same
 * reason-phrase title and debug-only detail as the {@see ExceptionListener}'s
 * Symfony-exception arm, so a config-mapped exception renders identically.
 *
 * It is registered at a low tag priority (`-1000`) so an application's own tagged
 * mappers (default priority `0`) are consulted first; the config map is the
 * fallback. It never maps a core
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} — the listener never
 * consults a mapper for one (bundle ADR 0073).
 *
 * When a throwable is an instance of several mapped classes (a subclass hierarchy),
 * the **most-specific** (most-derived) mapped class wins, so a configured subtype
 * status overrides a configured ancestor's.
 */
final class ConfiguredExceptionMapper implements ExceptionMapperInterface
{
    /**
     * @param array<class-string, int> $map exception class-string => HTTP status
     */
    public function __construct(
        private readonly array $map,
        private readonly bool $debug = false,
    ) {}

    public function map(\Throwable $throwable): ?ErrorResponse
    {
        $status = $this->statusFor($throwable);
        if ($status === null) {
            return null;
        }

        return ErrorResponse::fromErrors(new Error(
            status: (string) $status,
            title: HttpReasonPhrase::of($status),
            detail: $this->debug ? $throwable->getMessage() : '',
        ));
    }

    /**
     * The configured status for the throwable, or null when no mapped class
     * matches. When several mapped classes match (a subclass hierarchy), the
     * most-specific (most-derived) mapped class wins.
     */
    private function statusFor(\Throwable $throwable): ?int
    {
        $status = null;
        $matched = null;

        foreach ($this->map as $class => $candidateStatus) {
            if (!$throwable instanceof $class) {
                continue;
            }

            // Prefer the most-derived matched class: a later candidate wins only
            // when it is a subclass of the current best match.
            if ($matched === null || \is_subclass_of($class, $matched)) {
                $matched = $class;
                $status = $candidateStatus;
            }
        }

        return $status;
    }
}
