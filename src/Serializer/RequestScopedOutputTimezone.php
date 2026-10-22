<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The output timezone of the server handling the current operation, for render
 * paths that format `DateTime` values without going through a resource's
 * attributes. Pivot meta is one: the Doctrine provider casts each pivot column to
 * its wire value while it reads the association rows, long before the render, and
 * the provider is shared by every server. The {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}
 * sets it from the operation's Server at the start of each operation (bundle ADR 0123).
 *
 * @internal
 */
final class RequestScopedOutputTimezone implements ResetInterface
{
    private ?string $timezone = null;

    public function set(?string $timezone): void
    {
        $this->timezone = $timezone;
    }

    public function timezone(): ?string
    {
        return $this->timezone;
    }

    public function reset(): void
    {
        $this->timezone = null;
    }
}
