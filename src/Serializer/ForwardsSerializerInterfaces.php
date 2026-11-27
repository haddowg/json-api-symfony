<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\NestedFieldset;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\ResourceMeta;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\CountableControlsInterface;
use haddowg\JsonApi\Serializer\CountableSelfInterface;
use haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface;
use haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface;
use haddowg\JsonApi\Serializer\DeclaresRelationsInterface;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\RendersDeclaredLinksInterface;
use haddowg\JsonApi\Serializer\RendersResourceMetaInterface;
use haddowg\JsonApi\Serializer\SelfLinkAwareInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Serializer\SerializesNestedFieldsetsInterface;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;

/**
 * Forwards every serializer method, and every optional serializer interface core
 * reads off a rendered serializer, to the decorated {@see inner()} serializer. A
 * decorator that dropped one would silently lose what it drives on the decorated
 * render: the injected resolver carries the resource link contributor and the
 * singleton types, and the others drive counting, strict fieldsets, declared links,
 * the URI type and the self-link toggle. Each method forwards when the inner
 * serializer implements the interface, else returns the interface's documented
 * default.
 */
trait ForwardsSerializerInterfaces
{
    abstract protected function inner(): SerializerInterface;

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

    public function renderResourceMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        $inner = $this->inner();

        return $inner instanceof RendersResourceMetaInterface
            ? $inner->renderResourceMeta($object, $request)
            : ResourceMeta::render($inner, $object, $request);
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->inner()->getLinks($object, $request);
    }

    public function renderDeclaredLinks(mixed $object, JsonApiRequestInterface $request): array
    {
        $inner = $this->inner();

        return $inner instanceof RendersDeclaredLinksInterface ? $inner->renderDeclaredLinks($object, $request) : [];
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

    public function getExcludedIncludePaths(): array
    {
        $inner = $this->inner();

        return $inner instanceof IncludeControlsInterface ? $inner->getExcludedIncludePaths() : [];
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

    /**
     * The inner serializer's URI segment, so the parent's relationship links use it
     * rather than the JSON:API type.
     */
    public function uriType(): string
    {
        $inner = $this->inner();

        return $inner instanceof UriTypeAwareInterface ? $inner->uriType() : '';
    }

    /**
     * Left to the inner serializer, which the server already gave its resolver.
     */
    public function setSerializerResolver(SerializerResolverInterface $resolver): void {}

    /**
     * The inner serializer's resolver, so a singleton parent's links drop its id.
     */
    public function serializerResolver(): ?SerializerResolverInterface
    {
        $inner = $this->inner();

        return $inner instanceof SerializerResolverAwareInterface ? $inner->serializerResolver() : null;
    }

    /**
     * The inner serializer's own selection of a nested fieldset's keys when it makes
     * one, else its attribute's value, which core then trims.
     */
    public function getNestedFieldsetAttribute(mixed $object, JsonApiRequestInterface $request, string $name, NestedFieldset $fieldset): mixed
    {
        $inner = $this->inner();
        if ($inner instanceof SerializesNestedFieldsetsInterface) {
            return $inner->getNestedFieldsetAttribute($object, $request, $name, $fieldset);
        }
        $attribute = $inner->getAttributes($object, $request)[$name] ?? null;

        return $attribute === null ? null : $attribute($object, $request, $name);
    }
}
