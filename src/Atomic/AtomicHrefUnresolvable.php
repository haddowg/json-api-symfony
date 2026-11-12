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
 * An atomic operation targeted its endpoint by `href`, but the `href` matches no
 * JSON:API route on the resolved server — so the executor cannot resolve the
 * operation's target (its type / id / relationship).
 *
 * The executor matches an `href` against the Symfony router (the same defaults the
 * {@see \haddowg\JsonApiBundle\Operation\TargetResolver} reads on a direct call); a
 * miss is the client's fault — a malformed or unknown URL — so it is a `400`, pointed
 * at the operation's `href` member. Raised by the pre-flight scan, before anything is
 * written.
 */
final class AtomicHrefUnresolvable extends \RuntimeException implements DescribedErrorInterface
{
    public function __construct(
        public readonly string $href,
        public readonly string $pointer = '',
    ) {
        parent::__construct(\sprintf('The atomic operation href "%s" does not match any JSON:API route.', $href));
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATOMIC_HREF_UNRESOLVABLE',
            status: 400,
            title: 'Atomic operation href is unresolvable',
            context: ['href' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            exampleSource: '/atomic:operations/0/href',
            feature: ErrorFeature::AtomicOperations,
            scope: ErrorScope::kinds(OperationKind::Atomic),
            description: 'An operation\'s `href` matches no JSON:API route on this server. The server applied none of the batch. Correct the URL, or address the target with `ref`, and send the whole batch again.',
            exampleDetail: 'The atomic operation href "/articles/1/reviewers" does not match any JSON:API route.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['href' => $this->href],
                source: $this->pointer !== '' ? ErrorSource::fromPointer($this->pointer) : null,
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return self::describe()->status;
    }
}
