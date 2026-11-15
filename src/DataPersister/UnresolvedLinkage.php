<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Says which relationship writes store their linkage without the persister resolving
 * each target, so nothing finds a missing one unless the handler checks it first.
 *
 * @internal
 */
final class UnresolvedLinkage
{
    /**
     * Whether a write to `$relation` through `$persister` stores the identifiers as sent:
     * a `fillUsing()` hook takes them bare, and a declared `MorphToMany` writes them onto
     * its link rows.
     */
    public static function writtenBy(?DataPersisterInterface $persister, RelationInterface $relation): bool
    {
        return self::fillsThroughHook($relation)
            || ($relation instanceof MorphToMany && $relation->linkRowFields() !== null && $persister instanceof WritesLinkRowsInterface);
    }

    /**
     * Whether `$relation` declares a `fillUsing()` hook, which then owns how the
     * relationship is written.
     */
    public static function fillsThroughHook(RelationInterface $relation): bool
    {
        if (!$relation instanceof AbstractFieldValue) {
            return false;
        }

        static $state = null;
        $state ??= new \ReflectionProperty(AbstractFieldValue::class, 'state');
        $fieldState = $state->getValue($relation);

        return \is_object($fieldState) && \property_exists($fieldState, 'fillUsing') && $fieldState->fillUsing !== null;
    }
}
