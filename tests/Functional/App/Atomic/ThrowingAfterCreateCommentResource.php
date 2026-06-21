<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Atomic;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksTrait;
use haddowg\JsonApiBundle\Tests\Functional\App\Comment;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseCommentResource;

/**
 * The `comments` resource for the in-memory Atomic Operations kernel, augmented with
 * a throwing post-commit hook — the witness for the Slice D guarantee that a
 * SUCCESSFUL, durably-committed batch is NOT failed by a throwing `After*` hook
 * (bundle ADR 0088).
 *
 * `afterCreate` runs post-commit; under an atomic batch it is DEFERRED to the
 * post-commit drain. It throws only for the sentinel body `BOOM`, so the ordinary
 * comment-create cases in the conformance suite are unaffected. When a batch creates a
 * `BOOM` comment, the hook throws AFTER the batch has committed — the executor must
 * log it and still return the successful `200 atomic:results`, never turning the
 * committed batch into a `500`.
 */
final class ThrowingAfterCreateCommentResource extends BaseCommentResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public const string BOOM = 'BOOM';

    public function afterCreate(object $entity, HookContext $context): ?DataResponse
    {
        \assert($entity instanceof Comment);

        if ($entity->body === self::BOOM) {
            throw new \RuntimeException('post-commit afterCreate hook deliberately threw');
        }

        return null;
    }
}
