<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorContextType;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\Exception\ErrorSourceShape;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * An atomic operation's `href` resolves to a route, but not to one the operation can
 * target: it carries a query string or fragment (an operation acts on one resource or
 * relationship, never on a filtered set), it addresses a collection for an `update` or
 * `remove`, a single resource for an `add`, a related-resource endpoint rather than the
 * relationship's `/relationships/` URL, or a custom action. A `400` pointed at the
 * operation's `href` member, raised by the pre-flight scan before anything is written.
 */
final class AtomicHrefInvalid extends \RuntimeException implements DescribedErrorInterface
{
    public function __construct(
        public readonly string $href,
        string $reason,
        public readonly string $pointer,
    ) {
        parent::__construct(\sprintf('The atomic operation href "%s" %s.', $href, $reason));
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATOMIC_HREF_INVALID',
            status: 400,
            title: 'Atomic operation href is invalid',
            context: ['href' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            exampleSource: '/atomic:operations/0/href',
            feature: ErrorFeature::AtomicOperations,
            scope: ErrorScope::kinds(OperationKind::Atomic),
            description: 'An operation\'s `href` names a route the operation cannot act on. It carries a query string or fragment, addresses a custom action, addresses a related resource rather than the relationship\'s `/relationships/` URL, addresses a collection for an `update` or `remove`, or addresses a single resource for an `add`. The server applied none of the batch. Point the `href` at the URL the operation needs and send the whole batch again.',
            exampleDetail: 'The atomic operation href "/articles/1/author" addresses a related resource; a relationship operation targets its /relationships/ URL.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['href' => $this->href],
                source: ErrorSource::fromPointer($this->pointer),
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return self::describe()->status;
    }
}
