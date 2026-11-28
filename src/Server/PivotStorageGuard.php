<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\DataProvider\PivotAwareProviderInterface;

/**
 * Checks that a `belongsToMany` declaring stored pivot fields has a provider that reads its
 * pivot rows. Every member of a rendered pivot linkage carries its `meta.pivot`, and the
 * provider of the relation's related type reads the stored values, so one that can't would
 * render members without the pivot the OpenAPI document promises.
 *
 * @internal
 */
final class PivotStorageGuard
{
    /**
     * @throws \LogicException when a relation's stored pivot fields have no provider reading them
     */
    public static function check(DataProviderRegistry $providers, string $type, AbstractResource $resource): void
    {
        foreach ($resource->allFields() as $relation) {
            $relatedType = $relation instanceof BelongsToMany && $relation->storedPivotFields() !== [] ? ($relation->relatedTypes()[0] ?? null) : null;
            if ($relatedType === null || !$providers->supportsType($relatedType)) {
                continue;
            }

            $provider = $providers->forType($relatedType);
            if ($provider instanceof PivotAwareProviderInterface) {
                continue;
            }

            throw new \LogicException(\sprintf(
                'Relation "%s" on resource type "%s" declares stored pivot fields, but %s, the data provider of its related type "%s", reads no pivot rows. Implement %s on it, so each member of the relation renders its meta.pivot.',
                $relation->name(),
                $type,
                $provider::class,
                $relatedType,
                PivotAwareProviderInterface::class,
            ));
        }
    }
}
