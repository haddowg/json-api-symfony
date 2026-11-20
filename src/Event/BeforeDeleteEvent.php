<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\WrittenMeta;

/**
 * Dispatched before a delete. The {@see $entity} is the loaded target; a
 * subscriber that throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 * aborts the delete before it runs — the natural place for a delete guard (a
 * `409` when the resource is still referenced).
 *
 * A delete's meta-only body is validated by a listener at
 * {@see \haddowg\JsonApiBundle\EventListener\DeleteMetaSubscriber::PRIORITY}, below the
 * security gate and above the default `0`. It replaces {@see $request} with the request
 * carrying the typed meta, so a listener at a lower priority, a resource's `beforeDelete()`
 * hook included, reads it through {@see writtenMeta()}, while the gate refuses a caller
 * before an invalid body is reported. A listener above that priority sees the meta
 * unvalidated, and an empty {@see writtenMeta()}.
 */
final class BeforeDeleteEvent
{
    /**
     * @param (\Closure(JsonApiRequestInterface): JsonApiRequestInterface)|null $validatesMeta validates the body's meta and returns the request carrying it
     */
    public function __construct(
        public readonly string $type,
        // Not readonly: the meta listener replaces it with the request carrying the typed
        // meta. Treat it as read-only.
        public JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly string $serverName,
        private ?\Closure $validatesMeta = null,
    ) {}

    /**
     * The typed `meta` the delete sent, validated against what the type declares. Empty
     * until the meta listener has run, and for a delete that sent none.
     */
    public function writtenMeta(): WrittenMeta
    {
        return WrittenMeta::of($this->request);
    }

    /**
     * Validates the delete's meta once, and puts the typed values on {@see $request}. Later
     * calls do nothing.
     *
     * @internal the bundle's {@see \haddowg\JsonApiBundle\EventListener\DeleteMetaSubscriber} and its CRUD handler call it
     *
     * @throws \haddowg\JsonApiBundle\Validation\ValidationFailed when the meta is invalid
     */
    public function validateMeta(): void
    {
        $validates = $this->validatesMeta;
        if ($validates === null) {
            return;
        }
        $this->validatesMeta = null;
        $this->request = $validates($this->request);
    }
}
