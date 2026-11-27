<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
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
 * {@see CountableSelfInterface}), strict-fieldset/eager/relation declarations, the
 * self-link toggle, declared links, nested fieldsets and the injected resolver — by
 * implementing each and `instanceof`-delegating through {@see ForwardsSerializerInterfaces}. A decorator
 * that dropped one of these would silently break the corresponding feature on the
 * decorated render (e.g. core's count gate reads {@see CountableControlsInterface}
 * off the *primary* serializer, so a non-forwarding decorator turns a valid
 * `?withCount` into a `400`). Each method is a no-op-safe delegate: it forwards when
 * the inner implements the interface, else returns the interface's documented
 * default.
 */
abstract class AbstractPivotParentSerializer implements
    RendersResourceMetaInterface,
    SerializerInterface,
    IncludeControlsInterface,
    CountableControlsInterface,
    CountableSelfInterface,
    DeclaresFieldNamesInterface,
    DeclaresEagerLoadsInterface,
    DeclaresRelationsInterface,
    SelfLinkAwareInterface,
    RendersDeclaredLinksInterface,
    UriTypeAwareInterface,
    SerializerResolverAwareInterface,
    SerializesNestedFieldsetsInterface
{
    use ForwardsSerializerInterfaces;
    use RebindsPivotLinkage;

    /**
     * Each subclass rebuilds its pivot relation(s)' relationship callable here (the
     * one method a pivot parent decorator exists to override); everything else
     * delegates to {@see inner()}.
     */
    abstract public function getRelationships(mixed $object, JsonApiRequestInterface $request): array;
}
