<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Serializer;

/**
 * A member of a relation with stored pivot fields that its provider returned no pivot row
 * for. Its stored pivot fields have no values, and rendering them as `null` would hide a
 * data integrity fault behind a document the response schema refuses, so the render fails.
 */
final class MissingPivotRow extends \RuntimeException
{
    public static function for(string $parentType, string $relation, string $parentId, string $memberId, string $provider): self
    {
        return new self(\sprintf(
            'The "%s" relation of "%s" "%s" renders the member "%s", but %s returned no pivot row for it. Every member of a relation with stored pivot fields needs a pivot row.',
            $relation,
            $parentType,
            $parentId,
            $memberId,
            $provider,
        ));
    }
}
