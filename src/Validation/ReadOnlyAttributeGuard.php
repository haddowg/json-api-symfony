<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Validation;

use haddowg\JsonApi\Exception\AttributeWriteProhibited;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RejectsReadOnlyAttributesInterface;

/**
 * Refuses a write body that sends an attribute the client cannot write in that request,
 * when the server opted in (`json_api.reject_read_only_attributes`). Core makes the same
 * check when it hydrates; the write paths run this one first, before the Validator
 * bridge, so the `403` wins over a `422` for the same body.
 *
 * @internal
 */
final class ReadOnlyAttributeGuard
{
    /**
     * @throws AttributeWriteProhibited naming every offending attribute
     */
    public static function check(object $server, ?AbstractResource $resource, JsonApiRequestInterface $body, bool $creating): void
    {
        if ($resource === null || !$server instanceof RejectsReadOnlyAttributesInterface || !$server->rejectsReadOnlyAttributes()) {
            return;
        }

        $data = $body->getResource();
        $attributes = \is_array($data) ? ($data['attributes'] ?? null) : null;
        if (!\is_array($attributes)) {
            return;
        }

        $prohibited = [];
        foreach ($resource->allFields() as $field) {
            if ($field instanceof Id || $field instanceof RelationInterface || $field->isHidden()) {
                continue;
            }
            if (\array_key_exists($field->name(), $attributes) && $field->isReadOnlyFor($creating, $body)) {
                $prohibited[] = $field->name();
            }
        }

        if ($prohibited !== []) {
            throw new AttributeWriteProhibited($prohibited);
        }
    }
}
