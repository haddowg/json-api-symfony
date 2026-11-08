<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * An atomic operation's `href` resolves to a route, but not to one the operation can
 * target: it carries a query string or fragment (an operation acts on one resource or
 * relationship, never on a filtered set), it addresses a collection for an `update` or
 * `remove`, a single resource for an `add`, a related-resource endpoint rather than the
 * relationship's `/relationships/` URL, or a custom action. A `400` pointed at the
 * operation's `href` member, raised by the pre-flight scan before anything is written.
 */
final class AtomicHrefInvalid extends \RuntimeException implements JsonApiExceptionInterface
{
    public function __construct(
        public readonly string $href,
        string $reason,
        public readonly string $pointer,
    ) {
        parent::__construct(\sprintf('The atomic operation href "%s" %s.', $href, $reason));
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'ATOMIC_HREF_INVALID',
                title: 'Atomic operation href is invalid',
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer($this->pointer),
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return 400;
    }
}
