<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Operation;

use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Whether a relation declares a `fillUsing()` hook, which then owns how the relationship
 * is written.
 *
 * @internal
 */
final class RelationFillHook
{
    public static function declaredOn(RelationInterface $relation): bool
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
