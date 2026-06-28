<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\CountableControlsInterface;
use haddowg\JsonApi\Serializer\CountableSelfInterface;
use haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface;
use haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface;
use haddowg\JsonApi\Serializer\DeclaresRelationsInterface;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SelfLinkAwareInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * The shared base for the two pivot parent-serializer decorators
 * ({@see PivotParentSerializer}, {@see PivotLinkageParentSerializer}): it wraps an
 * inner parent serializer and delegates EVERYTHING to it except
 * {@see getRelationships()}, which each subclass overrides to rebind one-or-more
 * pivot relations' linkage to a {@see PivotMetaSerializer} so each identifier carries
 * its `meta.pivot`.
 *
 * Crucially it **transparently forwards every optional serializer-render interface**
 * the inner serializer (an {@see \haddowg\JsonApi\Resource\AbstractResource})
 * implements — counting ({@see CountableControlsInterface}/
 * {@see CountableSelfInterface}), strict-fieldset/eager/relation declarations and the
 * self-link toggle — by implementing each and `instanceof`-delegating. A decorator
 * that dropped one of these would silently break the corresponding feature on the
 * decorated render (e.g. core's count gate reads {@see CountableControlsInterface}
 * off the *primary* serializer, so a non-forwarding decorator turns a valid
 * `?withCount` into a `400`). Each method is a no-op-safe delegate: it forwards when
 * the inner implements the interface, else returns the interface's documented
 * default.
 */
abstract class AbstractPivotParentSerializer implements
    SerializerInterface,
    IncludeControlsInterface,
    CountableControlsInterface,
    CountableSelfInterface,
    DeclaresFieldNamesInterface,
    DeclaresEagerLoadsInterface,
    DeclaresRelationsInterface,
    SelfLinkAwareInterface
{
    use RebindsPivotLinkage;

    abstract protected function inner(): SerializerInterface;

    /**
     * Each subclass rebuilds its pivot relation(s)' relationship callable here (the
     * one method a pivot parent decorator exists to override); everything else
     * delegates to {@see inner()}.
     */
    abstract public function getRelationships(mixed $object, JsonApiRequestInterface $request): array;

    public function getType(mixed $object): string
    {
        return $this->inner()->getType($object);
    }

    public function getId(mixed $object): string
    {
        return $this->inner()->getId($object);
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner()->getMeta($object, $request);
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->inner()->getLinks($object, $request);
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner()->getAttributes($object, $request);
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->inner()->getDefaultIncludedRelationships($object);
    }

    public function getNonIncludableRelationships(JsonApiRequestInterface $request, mixed $object): array
    {
        $inner = $this->inner();

        return $inner instanceof IncludeControlsInterface
            ? $inner->getNonIncludableRelationships($request, $object)
            : [];
    }

    public function maxIncludeDepth(): ?int
    {
        $inner = $this->inner();

        return $inner instanceof IncludeControlsInterface ? $inner->maxIncludeDepth() : null;
    }

    public function getAllowedIncludePaths(): ?array
    {
        $inner = $this->inner();

        return $inner instanceof IncludeControlsInterface ? $inner->getAllowedIncludePaths() : null;
    }

    /**
     * @return list<string>
     */
    public function getCountableRelationships(mixed $object): array
    {
        $inner = $this->inner();

        return $inner instanceof CountableControlsInterface ? $inner->getCountableRelationships($object) : [];
    }

    public function isCountable(): bool
    {
        $inner = $this->inner();

        return $inner instanceof CountableSelfInterface && $inner->isCountable();
    }

    /**
     * @return list<string>
     */
    public function declaredFieldNames(): array
    {
        $inner = $this->inner();

        return $inner instanceof DeclaresFieldNamesInterface ? $inner->declaredFieldNames() : [];
    }

    /**
     * @return list<string>
     */
    public function eagerLoadRelationshipPaths(): array
    {
        $inner = $this->inner();

        return $inner instanceof DeclaresEagerLoadsInterface ? $inner->eagerLoadRelationshipPaths() : [];
    }

    public function relationNamedIncludingHidden(string $name): ?RelationInterface
    {
        $inner = $this->inner();

        return $inner instanceof DeclaresRelationsInterface ? $inner->relationNamedIncludingHidden($name) : null;
    }

    public function emitsSelfLink(): bool
    {
        $inner = $this->inner();

        // The base resource emits a self link by default; absent the interface a
        // serializer keeps that default rather than suppressing the link.
        return !$inner instanceof SelfLinkAwareInterface || $inner->emitsSelfLink();
    }
}
