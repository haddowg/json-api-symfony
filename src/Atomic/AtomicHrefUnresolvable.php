<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * An atomic operation targeted its endpoint by `href`, but the `href` matches no
 * JSON:API route on the resolved server — so the executor cannot resolve the
 * operation's target (its type / id / relationship).
 *
 * The executor matches an `href` against the Symfony router (the same defaults the
 * {@see \haddowg\JsonApiBundle\Operation\TargetResolver} reads on a direct call); a
 * miss is the client's fault — a malformed or unknown URL — so it is a `400`. Raised
 * either in the pre-flight scan (resolving the participating types) or while applying
 * the operation; in the latter case the loop pointer-prefixes it with the operation
 * index and rolls the batch back.
 */
final class AtomicHrefUnresolvable extends \RuntimeException implements JsonApiExceptionInterface
{
    public function __construct(public readonly string $href)
    {
        parent::__construct(\sprintf('The atomic operation href "%s" does not match any JSON:API route.', $href));
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'ATOMIC_HREF_UNRESOLVABLE',
                title: 'Atomic operation href is unresolvable',
                detail: $this->getMessage(),
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return 400;
    }
}
